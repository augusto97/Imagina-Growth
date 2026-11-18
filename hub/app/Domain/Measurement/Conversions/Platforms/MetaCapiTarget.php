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
use InvalidArgumentException;

/**
 * Meta Conversions API (web) y, para la Fase 2, Conversions API for Business
 * Messaging (action_source=business_messaging). POST /{dataset_id}/events.
 */
final class MetaCapiTarget implements ConversionTarget
{
    public function key(): string
    {
        return 'meta_capi';
    }

    public function platform(): Platform
    {
        return Platform::Meta;
    }

    public function supports(AdDestination $destination, string $event): bool
    {
        return $destination->platform === Platform::Meta
            && $destination->credential('dataset_id') !== null
            && $destination->credential('access_token') !== null
            && isset(config('igr-measurement.events')[$event]['meta']);
    }

    public function build(ConversionInput $input, AdDestination $destination): array
    {
        return [
            'event_name' => (string) config('igr-measurement.events')[$input->event]['meta'],
            'event_time' => $input->occurredAt,
            'event_id' => $input->eventId,
            'action_source' => (string) (config('igr-measurement.events')[$input->event]['meta_action_source'] ?? 'website'),
            'event_source_url' => $input->sourceUrl,
            'user_data' => $this->userData($input),
            'custom_data' => $this->customData($input),
        ];
    }

    /**
     * Evento de una venta iniciada en un anuncio de clic a WhatsApp (Fase 2).
     *
     * @return array<string, mixed>
     */
    public function buildBusinessMessaging(ConversionInput $input, string $eventName): array
    {
        /** @var list<string> $allowed */
        $allowed = config('igr-measurement.meta_business_messaging_events');
        if (! in_array($eventName, $allowed, true)) {
            throw new InvalidArgumentException("Meta no acepta {$eventName} para mensajería.");
        }
        if ($input->ctwaClid === null) {
            throw new InvalidArgumentException('Falta ctwa_clid para un evento de mensajería.');
        }
        $user = array_filter([
            'ctwa_clid' => $input->ctwaClid,
            'ph' => self::list(H::hash(H::phone($input->phone, $input->defaultCountry, Platform::Meta))),
            'em' => self::list(H::hash(H::email($input->email))),
            'external_id' => self::list(H::hash($input->contactId)),
        ]);

        return array_filter([
            'event_name' => $eventName,
            'event_time' => $input->occurredAt,
            'event_id' => $input->eventId,
            'action_source' => 'business_messaging',
            'messaging_channel' => 'whatsapp',
            'user_data' => $user,
            'custom_data' => $this->customData($input) ?: null,
        ], fn ($v) => $v !== null);
    }

    public function matchKeys(array $payload): array
    {
        $user = is_array($payload['user_data'] ?? null) ? $payload['user_data'] : [];
        $map = ['em' => 'email', 'ph' => 'phone', 'external_id' => 'external_id', 'fbc' => 'click_id', 'fbp' => 'browser_id', 'client_ip_address' => 'ip', 'fn' => 'name', 'ct' => 'city'];
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
        $body = ['data' => [$payload]];
        if ($destination->test_event_code !== null && $destination->test_event_code !== '') {
            $body['test_event_code'] = $destination->test_event_code;
        }
        try {
            $response = Http::timeout(15)
                ->withToken((string) $destination->credential('access_token'))
                ->asJson()
                ->post($this->url($destination).'/events', $body);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Meta', $e->getMessage());
        }
        if ($response->successful() && (int) $response->json('events_received', 0) >= 1) {
            return DeliveryResult::delivered($response->status());
        }

        return ErrorTranslator::meta($response);
    }

    public function test(AdDestination $destination): DeliveryResult
    {
        try {
            $response = Http::timeout(10)
                ->withToken((string) $destination->credential('access_token'))
                ->get($this->url($destination), ['fields' => 'id,name']);
        } catch (ConnectionException $e) {
            return ErrorTranslator::connection('Meta', $e->getMessage());
        }

        return $response->successful() ? DeliveryResult::delivered($response->status()) : ErrorTranslator::meta($response);
    }

    private function url(AdDestination $destination): string
    {
        return rtrim((string) config('igr-measurement.meta.graph_url'), '/').'/'.config('igr-measurement.meta.graph_version').'/'.rawurlencode((string) $destination->credential('dataset_id'));
    }

    /** @return array<string, mixed> */
    private function userData(ConversionInput $i): array
    {
        return array_filter([
            'em' => self::list(H::hash(H::email($i->email))),
            'ph' => self::list(H::hash(H::phone($i->phone, $i->defaultCountry, Platform::Meta))),
            'fn' => self::list(H::hash(H::name($i->firstName, Platform::Meta))),
            'ln' => self::list(H::hash(H::name($i->lastName, Platform::Meta))),
            'ct' => self::list(H::hash(H::city($i->city))),
            'st' => self::list(H::hash(H::state($i->state))),
            'zp' => self::list(H::hash(H::zip($i->zip))),
            'country' => self::list(H::hash(H::country($i->country))),
            'external_id' => self::list(H::hash($i->contactId)),
            'client_ip_address' => $i->ip,
            'client_user_agent' => $i->userAgent,
            'fbc' => $i->fbc,
            'fbp' => $i->fbp,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** @return array<string, mixed> */
    private function customData(ConversionInput $i): array
    {
        $data = [];
        if ($i->value !== null) {
            $data['value'] = round((float) $i->value, 2);
            $data['currency'] = $i->currency;
        }
        if ($i->items !== []) {
            $data['content_type'] = 'product';
            $data['content_ids'] = array_map(fn ($item) => $item['id'], $i->items);
            $data['contents'] = array_map(fn ($item) => array_filter([
                'id' => $item['id'],
                'quantity' => $item['quantity'],
                'item_price' => $item['price'] !== null ? round((float) $item['price'], 2) : null,
            ], fn ($v) => $v !== null), $i->items);
            $data['num_items'] = $i->numItems();
        }
        if ($i->orderId !== null) {
            $data['order_id'] = $i->orderId;
        }

        return $data;
    }

    /** @return list<string>|null */
    private static function list(?string $hash): ?array
    {
        return $hash === null ? null : [$hash];
    }
}
