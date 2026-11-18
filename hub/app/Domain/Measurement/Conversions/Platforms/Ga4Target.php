<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions\Platforms;

use App\Domain\Measurement\Conversions\ConversionInput;
use App\Domain\Measurement\Conversions\ConversionTarget;
use App\Domain\Measurement\Conversions\DeliveryResult;
use App\Domain\Measurement\Conversions\ErrorTranslator;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\UserDataHasher as H;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GA4 Measurement Protocol para el análisis (hub/CLAUDE.md §11). Con
 * test_event_code en el destino, los eventos llevan debug_mode y se ven en
 * DebugView. user_data de datos proporcionados por el usuario [verificar
 * nombres de campos vigentes].
 */
final class Ga4Target implements ConversionTarget
{
    public function key(): string
    {
        return 'ga4';
    }

    public function platform(): Platform
    {
        return Platform::Google;
    }

    public function supports(AdDestination $destination, string $event): bool
    {
        return $destination->platform === Platform::Google
            && $destination->credential('measurement_id') !== null
            && $destination->credential('api_secret') !== null
            && isset(config('igr-measurement.events')[$event]['google']);
    }

    public function build(ConversionInput $input, AdDestination $destination): array
    {
        $params = array_filter([
            'engagement_time_msec' => 1,
            'session_id' => $input->sessionId,
            'page_location' => $input->sourceUrl,
            'page_referrer' => $input->referrer,
            'transaction_id' => $input->event === 'order.paid' ? ($input->orderId ?? $input->eventId) : null,
            'value' => $input->value !== null ? round((float) $input->value, 2) : null,
            'currency' => $input->value !== null ? $input->currency : null,
            'items' => $input->items === [] ? null : array_map(fn ($item) => array_filter([
                'item_id' => $item['id'],
                'item_name' => $item['name'],
                'price' => $item['price'] !== null ? round((float) $item['price'], 2) : null,
                'quantity' => $item['quantity'],
                'item_category' => $item['category'],
            ], fn ($v) => $v !== null), $input->items),
            'debug_mode' => ($destination->test_event_code ?? '') !== '' ? true : null,
            'igr_event_id' => $input->eventId,
        ], fn ($v) => $v !== null);

        $address = array_filter([
            'sha256_first_name' => H::hash(H::name($input->firstName, Platform::Google)),
            'sha256_last_name' => H::hash(H::name($input->lastName, Platform::Google)),
            'city' => H::city($input->city),
            'region' => H::state($input->state),
            'postal_code' => H::zip($input->zip),
            'country' => H::country($input->country) !== null ? strtoupper((string) H::country($input->country)) : null,
        ], fn ($v) => $v !== null);
        $userData = array_filter([
            'sha256_email_address' => self::list(H::hash(H::email($input->email))),
            'sha256_phone_number' => self::list(H::hash(H::phone($input->phone, $input->defaultCountry, Platform::Google))),
            'address' => $address === [] ? null : [$address],
        ], fn ($v) => $v !== null);

        return array_filter([
            'client_id' => self::clientId($input),
            'timestamp_micros' => $input->occurredAt * 1_000_000,
            'user_data' => $userData === [] ? null : $userData,
            'events' => [[
                'name' => (string) config('igr-measurement.events')[$input->event]['google'],
                'params' => $params,
            ]],
        ], fn ($v) => $v !== null);
    }

    public function matchKeys(array $payload): array
    {
        $keys = ['browser_id'];
        $user = is_array($payload['user_data'] ?? null) ? $payload['user_data'] : [];
        if (! empty($user['sha256_email_address'])) {
            $keys[] = 'email';
        }
        if (! empty($user['sha256_phone_number'])) {
            $keys[] = 'phone';
        }

        return $keys;
    }

    public function send(AdDestination $destination, array $payload): DeliveryResult
    {
        try {
            $response = Http::timeout(10)->asJson()->post($this->url($destination, false), $payload);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Google Analytics', $e->getMessage());
        }

        // El Measurement Protocol responde 2xx aunque el evento sea inválido: se valida con «probar conexión».
        return $response->successful() ? DeliveryResult::delivered($response->status()) : ErrorTranslator::google($response, 'GA4');
    }

    public function test(AdDestination $destination): DeliveryResult
    {
        $payload = ['client_id' => '1234567890.1234567890', 'events' => [['name' => 'page_view', 'params' => ['engagement_time_msec' => 1]]]];
        try {
            $response = Http::timeout(10)->asJson()->post($this->url($destination, true), $payload);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Google Analytics', $e->getMessage());
        }
        $messages = (array) ($response->json('validationMessages') ?? []);
        if ($response->successful() && $messages === []) {
            return DeliveryResult::delivered($response->status());
        }
        $first = is_array($messages[0] ?? null) ? $messages[0] : [];

        return new DeliveryResult(DeliveryResult::FAILED, $response->status(), 'google:ga4_validation', __('GA4 rechazó el evento de prueba: :msg', ['msg' => (string) ($first['description'] ?? __('revisa el ID de medición y el secreto de la API.'))]));
    }

    private function url(AdDestination $destination, bool $debug): string
    {
        $base = (string) config($debug ? 'igr-measurement.google.ga4_debug_url' : 'igr-measurement.google.ga4_url');

        return $base.'?'.http_build_query(['measurement_id' => $destination->credential('measurement_id'), 'api_secret' => $destination->credential('api_secret')]);
    }

    /** El client_id del cookie _ga («GA1.1.123.456» → «123.456») o uno estable derivado del visitante. */
    public static function clientId(ConversionInput $input): string
    {
        $ga = $input->gaClientId;
        if ($ga !== null && preg_match('/(\d+\.\d+)$/', $ga, $m) === 1) {
            return $m[1];
        }
        $seed = $input->anonymousId ?? $input->contactId ?? $input->eventId;

        return sprintf('%u.%u', crc32('igr:'.$seed), crc32('igr2:'.$seed));
    }

    /** @return list<string>|null */
    private static function list(?string $hash): ?array
    {
        return $hash === null ? null : [$hash];
    }
}
