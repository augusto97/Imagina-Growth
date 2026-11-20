<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Configuration set de SES con sus eventos hacia SNS (F5). Todos los correos por SES salen con
 * `IGR_SES_CONFIGURATION_SET` (`igr-events`) y la identidad de cada dominio se crea con él; sin el configuration set SES
 * rechaza el envío, y sin su destino de eventos no llegan entregas, rebotes, quejas ni aperturas (ni se suprimen los
 * correos que rebotan). Se crea solo, de forma idempotente, al conectar o probar un canal SES y con `igr:ses:setup`.
 *
 * API de SES v2 (verificada el 20 nov 2026):
 * - CreateConfigurationSet (https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_CreateConfigurationSet.html):
 *   `AlreadyExistsException` si ya existe.
 * - Get/Create/UpdateConfigurationSetEventDestination
 *   (https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_CreateConfigurationSetEventDestination.html), con
 *   `SnsDestination.TopicArn` y `MatchingEventTypes`. Sin CLICK: los enlaces ya pasan por /c/ y SES los reescribiría.
 * - Permisos de IAM (https://docs.aws.amazon.com/service-authorization/latest/reference/list_sesv2.html):
 *   `ses:CreateConfigurationSet`, `ses:GetConfigurationSet`, `ses:GetConfigurationSetEventDestinations`,
 *   `ses:CreateConfigurationSetEventDestination` y `ses:UpdateConfigurationSetEventDestination` sobre
 *   `arn:aws:ses:<región>:<cuenta>:configuration-set/igr-events`, además de `ses:SendEmail` sobre la identidad y el
 *   configuration set.
 * - El tema de SNS debe ser estándar (no FIFO) y su política debe dejar publicar a `ses.amazonaws.com` con
 *   `AWS:SourceArn` = el ARN del configuration set
 *   (https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-sns.html).
 */
class SesEventSetup
{
    public const DESTINATION = 'igr-sns';

    /** Eventos que lee SesDriver::handleWebhook. */
    public const EVENTS = ['DELIVERY', 'BOUNCE', 'COMPLAINT', 'OPEN', 'REJECT', 'RENDERING_FAILURE'];

    public const CACHE_KEY = 'igr:ses:setup';

    /** Acción de IAM que pide cada operación (para decir cuál falta). */
    private const IAM = [
        'CreateConfigurationSet' => 'ses:CreateConfigurationSet',
        'GetConfigurationSet' => 'ses:GetConfigurationSet',
        'GetConfigurationSetEventDestinations' => 'ses:GetConfigurationSetEventDestinations',
        'CreateConfigurationSetEventDestination' => 'ses:CreateConfigurationSetEventDestination',
        'UpdateConfigurationSetEventDestination' => 'ses:UpdateConfigurationSetEventDestination',
    ];

    public function __construct(private readonly SesClientFactory $factory) {}

    /**
     * Crea lo que falte y deja el resultado para igr:doctor.
     *
     * @return array{status: 'ok'|'no_topic'|'error', message: string, configuration_set: string, topic: string|null, permission: string|null, changed: bool, checked_at: string}
     */
    public function ensure(): array
    {
        $set = self::configurationSet();
        $topic = self::topic();
        if (! $this->factory->configured()) {
            return self::result('error', __('Amazon SES no está configurado en la plataforma (faltan AWS_ACCESS_KEY_ID y AWS_SECRET_ACCESS_KEY).'));
        }

        $operation = 'CreateConfigurationSet';
        $changed = false;
        try {
            $client = $this->factory->make();
            $changed = $this->ensureConfigurationSet($client, $set, $operation);
            if ($topic === null) {
                $out = self::result('no_topic', __('El configuration set :set existe, pero sin IGR_SES_SNS_TOPIC_ARN no tiene a dónde mandar las entregas, los rebotes y las quejas.', ['set' => $set]), null, $changed);
            } else {
                $changed = $this->ensureDestination($client, $set, $topic, $operation) || $changed;
                $out = self::result('ok', __('El configuration set :set manda sus eventos al tema de SNS.', ['set' => $set]), null, $changed);
            }
        } catch (CredentialsException) {
            $out = self::result('error', __('Las credenciales de Amazon SES de la plataforma no son válidas.'));
        } catch (AwsException $e) {
            $code = (string) $e->getAwsErrorCode();
            $permission = in_array($code, ['AccessDenied', 'AccessDeniedException', 'NotAuthorized', 'UnauthorizedOperation'], true) ? self::IAM[$operation] : null;
            $out = self::result('error', $permission !== null
                ? __('A las credenciales de Amazon SES de la plataforma les falta el permiso :permission en IAM. Agrégalo a su política (docs/despliegue.md §8) y vuelve a intentar.', ['permission' => $permission])
                : __('Amazon SES no dejó preparar el configuration set :set: :error', ['set' => $set, 'error' => $e->getAwsErrorMessage() ?: $code]), $permission);
            Log::warning('igr.ses.setup_failed', ['operation' => $operation, 'code' => $code]);
        }
        Cache::forever(self::CACHE_KEY, $out);

        return $out;
    }

    /**
     * @param  'ok'|'no_topic'|'error'  $status
     * @return array{status: 'ok'|'no_topic'|'error', message: string, configuration_set: string, topic: string|null, permission: string|null, changed: bool, checked_at: string}
     */
    private static function result(string $status, string $message, ?string $permission = null, bool $changed = false): array
    {
        return [
            'status' => $status, 'message' => $message, 'configuration_set' => self::configurationSet(), 'topic' => self::topic(), 'permission' => $permission,
            'changed' => $changed, 'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Último resultado guardado (o null si nunca se revisó).
     *
     * @return array<string, mixed>|null
     */
    public static function lastResult(): ?array
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_array($value) ? $value : null;
    }

    public static function configurationSet(): string
    {
        return (string) config('igr-messaging.ses.configuration_set', 'igr-events');
    }

    /** El primer tema de IGR_SES_SNS_TOPIC_ARN (puede haber varios separados por comas para aceptar avisos). */
    public static function topic(): ?string
    {
        $topics = array_values(array_filter(array_map('trim', explode(',', (string) config('igr-messaging.ses.sns_topic_arn')))));

        return $topics[0] ?? null;
    }

    /** true si lo creó. */
    private function ensureConfigurationSet(SesV2Client $client, string $set, string &$operation): bool
    {
        try {
            $operation = 'CreateConfigurationSet';
            $client->createConfigurationSet(['ConfigurationSetName' => $set]);

            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AlreadyExistsException') {
                return false;
            }
            // Sin permiso para crear puede que ya exista (lo creó alguien a mano): si se puede leer, sirve.
            if (in_array($e->getAwsErrorCode(), ['AccessDenied', 'AccessDeniedException'], true)) {
                $operation = 'GetConfigurationSet';
                try {
                    $client->getConfigurationSet(['ConfigurationSetName' => $set]);

                    return false;
                } catch (AwsException) {
                    $operation = 'CreateConfigurationSet';
                }
            }
            throw $e;
        }
    }

    /** true si lo creó o lo corrigió. */
    private function ensureDestination(SesV2Client $client, string $set, string $topic, string &$operation): bool
    {
        $operation = 'GetConfigurationSetEventDestinations';
        $existing = null;
        foreach ((array) ($client->getConfigurationSetEventDestinations(['ConfigurationSetName' => $set])['EventDestinations'] ?? []) as $destination) {
            if (is_array($destination) && ($destination['Name'] ?? null) === self::DESTINATION) {
                $existing = $destination;
            }
        }
        $wanted = ['Enabled' => true, 'MatchingEventTypes' => self::EVENTS, 'SnsDestination' => ['TopicArn' => $topic]];
        if ($existing !== null) {
            $types = array_map('strval', (array) ($existing['MatchingEventTypes'] ?? []));
            sort($types);
            $want = self::EVENTS;
            sort($want);
            if (($existing['Enabled'] ?? false) === true && $types === $want && ($existing['SnsDestination']['TopicArn'] ?? null) === $topic) {
                return false;
            }
            $operation = 'UpdateConfigurationSetEventDestination';
            $client->updateConfigurationSetEventDestination(['ConfigurationSetName' => $set, 'EventDestinationName' => self::DESTINATION, 'EventDestination' => $wanted]);

            return true;
        }
        $operation = 'CreateConfigurationSetEventDestination';
        try {
            $client->createConfigurationSetEventDestination(['ConfigurationSetName' => $set, 'EventDestinationName' => self::DESTINATION, 'EventDestination' => $wanted]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                throw $e;
            }
            // Otro proceso lo creó al mismo tiempo: se deja como se quiere.
            $operation = 'UpdateConfigurationSetEventDestination';
            $client->updateConfigurationSetEventDestination(['ConfigurationSetName' => $set, 'EventDestinationName' => self::DESTINATION, 'EventDestination' => $wanted]);
        }

        return true;
    }
}
