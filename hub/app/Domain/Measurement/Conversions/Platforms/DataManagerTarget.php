<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions\Platforms;

use App\Domain\Measurement\Conversions\ConversionInput;
use App\Domain\Measurement\Conversions\ConversionTarget;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Conversions\ErrorTranslator;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\GoogleAccessToken;
use App\Domain\Measurement\Support\UserDataHasher as H;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Data Manager API: conversiones mejoradas de compras y leads hacia
 * una acción de conversión de Google Ads (POST /v1/events:ingest, alcance
 * https://www.googleapis.com/auth/datamanager). En modo de prueba usa
 * validateOnly. Campos de userData/consent [verificar] contra la referencia.
 */
final class DataManagerTarget implements ConversionTarget
{
    /** Evento de Imagina Growth → clave de la acción de conversión en el config del destino. */
    private const ACTIONS = ['order.paid' => 'conversion_action_purchase', 'form.submitted' => 'conversion_action_lead', 'lead.won' => 'conversion_action_lead_won'];

    public function __construct(private readonly GoogleAccessToken $tokens) {}

    public function key(): string
    {
        return 'google_data_manager';
    }

    public function platform(): Platform
    {
        return Platform::Google;
    }

    public function supports(AdDestination $destination, string $event): bool
    {
        return $destination->platform === Platform::Google
            && isset(self::ACTIONS[$event])
            && $destination->credential(self::ACTIONS[$event]) !== null
            && $destination->credential('ads_customer_id') !== null
            && $destination->hasGoogleAuth();
    }

    public function build(ConversionInput $input, AdDestination $destination): array
    {
        $identifiers = [];
        if (($email = H::hash(H::email($input->email))) !== null) {
            $identifiers[] = ['emailAddress' => $email];
        }
        if (($phone = H::hash(H::phone($input->phone, $input->defaultCountry, Platform::Google))) !== null) {
            $identifiers[] = ['phoneNumber' => $phone];
        }
        $address = array_filter([
            'givenName' => H::hash(H::name($input->firstName, Platform::Google)),
            'familyName' => H::hash(H::name($input->lastName, Platform::Google)),
            'regionCode' => H::country($input->country) !== null ? strtoupper((string) H::country($input->country)) : null,
            'postalCode' => H::zip($input->zip),
        ], fn ($v) => $v !== null);
        if (isset($address['givenName'], $address['familyName'], $address['regionCode'])) {
            $identifiers[] = ['address' => $address];
        }

        $event = array_filter([
            'transactionId' => $input->orderId ?? $input->eventId,
            'eventTimestamp' => CarbonImmutable::createFromTimestamp($input->occurredAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            'conversionValue' => $input->value !== null ? round((float) $input->value, 2) : null,
            'currency' => $input->value !== null ? $input->currency : null,
            'eventSource' => (string) (config('igr-measurement.events')[$input->event]['google_event_source'] ?? 'WEB'),
            'userData' => $identifiers === [] ? null : ['userIdentifiers' => $identifiers],
            'adIdentifiers' => array_filter(['gclid' => $input->gclid, 'gbraid' => $input->gbraid, 'wbraid' => $input->wbraid]) ?: null,
        ], fn ($v) => $v !== null);

        $destinationBlock = array_filter([
            'operatingAccount' => ['accountType' => 'GOOGLE_ADS', 'accountId' => self::digits((string) $destination->credential('ads_customer_id'))],
            'loginAccount' => $destination->credential('ads_login_customer_id') !== null
                ? ['accountType' => 'GOOGLE_ADS', 'accountId' => self::digits((string) $destination->credential('ads_login_customer_id'))]
                : null,
            'productDestinationId' => (string) $destination->credential(self::ACTIONS[$input->event] ?? 'conversion_action_purchase'),
        ]);

        return array_filter([
            'destinations' => [$destinationBlock],
            'encoding' => 'HEX',
            // Solo se envía con consentimiento de marketing (ConversionRouter).
            'consent' => ['adUserData' => 'CONSENT_GRANTED', 'adPersonalization' => 'CONSENT_GRANTED'],
            'validateOnly' => ($destination->test_event_code ?? '') !== '' ? true : null,
            'events' => [$event],
        ], fn ($v) => $v !== null);
    }

    public function matchKeys(array $payload): array
    {
        $event = $payload['events'][0] ?? [];
        $keys = [];
        foreach ((array) ($event['userData']['userIdentifiers'] ?? []) as $identifier) {
            $keys[] = match (true) {
                isset($identifier['emailAddress']) => 'email',
                isset($identifier['phoneNumber']) => 'phone',
                default => 'name',
            };
        }
        if (! empty($event['adIdentifiers'])) {
            $keys[] = 'click_id';
        }

        return $keys;
    }

    public function send(AdDestination $destination, array $payload): DeliveryResult
    {
        try {
            $token = $this->tokens->for($destination);
            $response = Http::timeout(15)->withToken($token)->asJson()->post((string) config('igr-measurement.google.data_manager_url'), $payload);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Google', $e->getMessage());
        } catch (RuntimeException $e) {
            return new DeliveryResult(DeliveryResult::FAILED, 0, 'google:oauth', $e->getMessage());
        }

        return $response->successful() ? DeliveryResult::delivered($response->status()) : ErrorTranslator::google($response, 'Data Manager');
    }

    public function test(AdDestination $destination): DeliveryResult
    {
        $payload = $this->build(new ConversionInput(
            workspaceId: $destination->workspace_id, siteId: '', event: 'order.paid', eventId: 'igr-test', occurredAt: time(),
            sourceUrl: null, referrer: null, ip: null, userAgent: null, contactId: null, anonymousId: null, sessionId: null,
            defaultCountry: 'CO', value: '1.00', currency: 'COP', orderId: 'igr-test-'.time(),
        ), $destination);
        $payload['validateOnly'] = true;

        return $this->send($destination, $payload);
    }

    private static function digits(string $id): string
    {
        return (string) preg_replace('/\D/', '', $id);
    }
}
