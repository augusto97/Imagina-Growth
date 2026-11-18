<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Drivers;

use App\Domain\Messaging\Channels\ChannelDriver;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Data\SendResult;
use App\Domain\Messaging\Channels\Data\StatusUpdate;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\EmailBuilder;
use App\Domain\Messaging\Support\SesClientFactory;
use Aws\Exception\AwsException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Amazon SES v2 (correo por defecto, M07). Cada espacio de trabajo verifica su
 * dominio (DKIM con 3 CNAME) en la cuenta de SES de la plataforma; los eventos
 * (entrega, rebote, queja, apertura) llegan por el configuration set hacia SNS.
 */
final class SesDriver implements ChannelDriver
{
    private const RETRYABLE = ['Throttling', 'ThrottlingException', 'TooManyRequestsException', 'ServiceUnavailable', 'InternalFailure', 'RequestTimeout'];

    public function __construct(private readonly SesClientFactory $factory) {}

    public function send(ChannelAccount $account, OutgoingMessage $message): SendResult
    {
        $email = EmailBuilder::build($account, $message);
        try {
            $result = $this->factory->make()->sendEmail([
                'FromEmailAddress' => $email->getFrom()[0]->toString(),
                'Destination' => ['ToAddresses' => [$message->to]],
                'Content' => ['Raw' => ['Data' => $email->toString()]],
                'ConfigurationSetName' => (string) config('igr-messaging.ses.configuration_set'),
                'EmailTags' => [['Name' => 'igr_message', 'Value' => $message->messageId]],
            ]);
        } catch (AwsException $e) {
            $code = (string) $e->getAwsErrorCode();

            return SendResult::failed($e->getAwsErrorMessage() ?: $e->getMessage(), $code, in_array($code, self::RETRYABLE, true) || $e->getStatusCode() >= 500);
        }

        return SendResult::sent(is_string($result['MessageId'] ?? null) ? $result['MessageId'] : null);
    }

    /**
     * Crea (o lee) la identidad del dominio en SES y devuelve los 3 tokens DKIM.
     *
     * @return list<string>
     */
    public function ensureDomainIdentity(string $domain): array
    {
        $client = $this->factory->make();
        try {
            $result = $client->createEmailIdentity(['EmailIdentity' => $domain, 'ConfigurationSetName' => (string) config('igr-messaging.ses.configuration_set')]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                throw $e;
            }
            $result = $client->getEmailIdentity(['EmailIdentity' => $domain]);
        }

        return array_values(array_map('strval', (array) ($result['DkimAttributes']['Tokens'] ?? [])));
    }

    /** Estado de la identidad en SES: SUCCESS, PENDING, FAILED, TEMPORARY_FAILURE o NOT_STARTED. */
    public function dkimStatus(string $domain): string
    {
        $result = $this->factory->make()->getEmailIdentity(['EmailIdentity' => $domain]);

        return (string) ($result['DkimAttributes']['Status'] ?? 'NOT_STARTED');
    }

    /** Una notificación de SNS ya verificada (SnsMessage) con un evento del configuration set. */
    public function handleWebhook(Request $request, ?ChannelAccount $account): WebhookBatch
    {
        $batch = new WebhookBatch;
        $event = json_decode((string) ($request->json('Message') ?? ''), true);
        if (! is_array($event)) {
            return $batch;
        }
        $type = (string) ($event['eventType'] ?? $event['notificationType'] ?? '');
        $id = (string) ($event['mail']['messageId'] ?? '');
        if ($id === '') {
            return $batch;
        }
        $at = fn (?string $iso) => $iso !== null ? CarbonImmutable::parse($iso) : CarbonImmutable::now();
        switch ($type) {
            case 'Delivery':
                $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate($id, 'delivered', $at($event['delivery']['timestamp'] ?? null))];
                break;
            case 'Bounce':
                $hard = ($event['bounce']['bounceType'] ?? '') === 'Permanent';
                foreach ((array) ($event['bounce']['bouncedRecipients'] ?? [['emailAddress' => null]]) as $r) {
                    $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate(
                        $id, 'bounced', $at($event['bounce']['timestamp'] ?? null),
                        isset($r['diagnosticCode']) ? (string) $r['diagnosticCode'] : (string) ($event['bounce']['bounceSubType'] ?? ''),
                        (string) ($event['bounce']['bounceSubType'] ?? ''), $hard ? 'hard' : 'soft', false,
                        isset($r['emailAddress']) ? (string) $r['emailAddress'] : null,
                    )];
                }
                break;
            case 'Complaint':
                foreach ((array) ($event['complaint']['complainedRecipients'] ?? [['emailAddress' => null]]) as $r) {
                    $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate(
                        $id, 'complained', $at($event['complaint']['timestamp'] ?? null), null, null, null, false,
                        isset($r['emailAddress']) ? (string) $r['emailAddress'] : null,
                    )];
                }
                break;
            case 'Open':
                $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate(
                    $id, 'opened', $at($event['open']['timestamp'] ?? null), null, null, null,
                    self::isAppleMpp((string) ($event['open']['ipAddress'] ?? ''), (string) ($event['open']['userAgent'] ?? '')),
                )];
                break;
            case 'Reject':
            case 'RenderingFailure':
                $batch->statuses[] = ['account' => null, 'update' => new StatusUpdate($id, 'failed', CarbonImmutable::now(), (string) ($event['reject']['reason'] ?? $type), $type)];
                break;
        }

        return $batch;
    }

    /**
     * Aperturas automáticas de Apple Mail Privacy Protection: llegan desde la red de
     * Apple (17.0.0.0/8) con un user agent genérico **[verificar]** con datos reales.
     */
    public static function isAppleMpp(string $ip, string $userAgent): bool
    {
        return str_starts_with($ip, '17.') || ($userAgent !== '' && ! str_contains($userAgent, 'Mozilla/5.0 (') && str_contains($userAgent, 'Mozilla/5.0'));
    }

    public function syncTemplates(ChannelAccount $account): int
    {
        return 0;
    }

    public function estimateCost(ChannelAccount $account, string $country, MessageKind $kind): float
    {
        return (float) config('igr-messaging.costs.email_usd');
    }

    public function capabilities(): array
    {
        return ['marketing' => true, 'templates' => false, 'free_text' => true, 'media' => true, 'buttons' => true, 'rate_per_second' => (int) config('igr-messaging.ses.rate_per_second', 14)];
    }
}
