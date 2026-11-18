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
 * TikTok Events API 2.0 (POST /open_api/v1.3/event/track/). Nombres de evento
 * estándar y normalización del teléfono [verificar].
 */
final class TikTokTarget implements ConversionTarget
{
    public function key(): string
    {
        return 'tiktok_events';
    }

    public function platform(): Platform
    {
        return Platform::TikTok;
    }

    public function supports(AdDestination $destination, string $event): bool
    {
        return $destination->platform === Platform::TikTok
            && $destination->credential('pixel_code') !== null
            && $destination->credential('access_token') !== null
            && isset(config('igr-measurement.events')[$event]['tiktok']);
    }

    public function build(ConversionInput $input, AdDestination $destination): array
    {
        $properties = array_filter([
            'currency' => $input->value !== null ? $input->currency : null,
            'value' => $input->value !== null ? round((float) $input->value, 2) : null,
            'content_type' => $input->items !== [] ? 'product' : null,
            'contents' => $input->items === [] ? null : array_map(fn ($item) => array_filter([
                'content_id' => $item['id'],
                'content_name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] !== null ? round((float) $item['price'], 2) : null,
            ], fn ($v) => $v !== null), $input->items),
            'order_id' => $input->orderId,
        ], fn ($v) => $v !== null);

        return [
            'event' => (string) config('igr-measurement.events')[$input->event]['tiktok'],
            'event_time' => $input->occurredAt,
            'event_id' => $input->eventId,
            'user' => array_filter([
                'email' => H::hash(H::email($input->email)),
                'phone' => H::hash(H::phone($input->phone, $input->defaultCountry, Platform::TikTok)),
                'external_id' => H::hash($input->contactId),
                'ttclid' => $input->ttclid,
                'ttp' => $input->ttp,
                'ip' => $input->ip,
                'user_agent' => $input->userAgent,
            ], fn ($v) => $v !== null),
            'properties' => $properties === [] ? new \stdClass : $properties,
            'page' => array_filter(['url' => $input->sourceUrl, 'referrer' => $input->referrer], fn ($v) => $v !== null),
        ];
    }

    public function matchKeys(array $payload): array
    {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $map = ['email' => 'email', 'phone' => 'phone', 'external_id' => 'external_id', 'ttclid' => 'click_id', 'ttp' => 'browser_id', 'ip' => 'ip'];
        $keys = [];
        foreach ($map as $field => $key) {
            if (! empty($user[$field])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function send(AdDestination $destination, array $payload): DeliveryResult
    {
        $body = array_filter([
            'event_source' => 'web',
            'event_source_id' => $destination->credential('pixel_code'),
            'test_event_code' => ($destination->test_event_code ?? '') !== '' ? $destination->test_event_code : null,
            'data' => [$payload],
        ], fn ($v) => $v !== null);
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Access-Token' => (string) $destination->credential('access_token')])
                ->asJson()
                ->post((string) config('igr-measurement.tiktok.events_url'), $body);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('TikTok', $e->getMessage());
        }
        if ($response->successful() && (int) $response->json('code', -1) === 0) {
            return DeliveryResult::delivered($response->status());
        }

        return ErrorTranslator::tiktok($response);
    }

    public function test(AdDestination $destination): DeliveryResult
    {
        if (($destination->test_event_code ?? '') === '') {
            return new DeliveryResult(DeliveryResult::FAILED, 0, 'tiktok:no_test_code', __('Para probar sin registrar conversiones reales, agrega el código de prueba de TikTok (Administrador de eventos → Probar eventos).'));
        }

        return $this->send($destination, [
            'event' => 'Pageview', 'event_time' => time(), 'event_id' => 'igr-test-'.time(),
            'user' => ['ip' => '127.0.0.1', 'user_agent' => 'ImaginaGrowth/1.0 (prueba)'], 'properties' => new \stdClass, 'page' => ['url' => 'https://growth.imagina.cloud/prueba'],
        ]);
    }
}
