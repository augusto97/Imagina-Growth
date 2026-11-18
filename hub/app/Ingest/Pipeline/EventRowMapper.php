<?php

declare(strict_types=1);

namespace App\Ingest\Pipeline;

use App\Ingest\Enrichment\ChannelClassifier;
use App\Ingest\Enrichment\DeviceDetector;
use Carbon\CarbonImmutable;

/**
 * Convierte un sobre validado en la fila de ClickHouse `events`
 * (modelo-de-datos.md §10): enriquece (canal, dispositivo, geo) y quita la PII.
 */
final class EventRowMapper
{
    private const CLICK_ID_ORDER = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid'];

    public function __construct(
        private readonly ChannelClassifier $channels,
        private readonly DeviceDetector $devices,
        private readonly PiiSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $e  sobre (arreglo asociativo)
     * @param  array{ws_ch: int, site_ch: int, site_host: string, received_ms: int, ua: string}  $meta
     * @return array<string, mixed>
     */
    public function map(array $e, array $meta, ?string $contactId): array
    {
        $ctx = is_array($e['context'] ?? null) ? $e['context'] : [];
        $identity = is_array($e['identity'] ?? null) ? $e['identity'] : [];
        $props = is_array($e['properties'] ?? null) ? $e['properties'] : [];
        $event = (string) $e['event'];

        // Para eventos de servidor, el contacto de la visita está en last_touch (cookie _igr_src).
        $touch = is_array($ctx['last_touch'] ?? null) ? $ctx['last_touch'] : [];
        $utm = array_filter(is_array($ctx['utm'] ?? null) ? $ctx['utm'] : (is_array($touch['utm'] ?? null) ? $touch['utm'] : []));
        $clicks = array_filter(is_array($ctx['click_ids'] ?? null) ? $ctx['click_ids'] : (is_array($touch['click_ids'] ?? null) ? $touch['click_ids'] : []));
        $referrer = (string) ($ctx['page']['referrer'] ?? ($touch['referrer'] ?? ''));

        $channel = '';
        if ($utm !== [] || $clicks !== [] || $referrer !== '' || ($e['source']['type'] ?? '') === 'browser' || ! empty($identity['link_token'])) {
            $channel = $this->channels->classify([
                'utm' => $utm,
                'click_ids' => $clicks,
                'referrer' => $referrer,
                'site_host' => $meta['site_host'],
                'link_token' => $identity['link_token'] ?? null,
            ]);
        }
        $clickKind = '';
        foreach (self::CLICK_ID_ORDER as $k) {
            if (! empty($clicks[$k])) {
                $clickKind = $k;
                break;
            }
        }

        $device = is_array($ctx['device'] ?? null) ? $ctx['device'] : [];
        if (($device['type'] ?? '') === '') {
            $device = $this->devices->detect($ctx['user_agent'] ?? ($meta['ua'] !== '' ? $meta['ua'] : null));
        }

        $order = is_array($props['order'] ?? null) ? $props['order'] : null;
        $geo = $this->geo($order, $props);

        $revenue = '0';
        $currency = (string) ($ctx['currency'] ?? '');
        if ($order !== null) {
            $currency = (string) ($order['currency'] ?? $currency);
            if (in_array($event, ['order.paid', 'order.created'], true)) {
                $revenue = (string) ($order['totals']['total'] ?? '0');
            }
        }
        if ($event === 'order.refunded') {
            $revenue = (string) ($props['amount'] ?? '0');
        }

        $path = (string) ($ctx['page']['path'] ?? '');

        return [
            'workspace_id' => $meta['ws_ch'],
            'site_id' => $meta['site_ch'],
            'event_id' => (string) $e['event_id'],
            'event' => $event,
            'occurred_at' => self::chTime((string) $e['occurred_at']),
            'received_at' => CarbonImmutable::createFromTimestampMs($meta['received_ms'])->utc()->format('Y-m-d H:i:s.v'),
            'source_type' => (string) ($e['source']['type'] ?? ''),
            'anonymous_id' => (string) ($identity['anonymous_id'] ?? ''),
            'session_id' => (string) ($identity['session_id'] ?? ''),
            'contact_id' => $contactId ?? '',
            'page_type' => (string) ($ctx['page_type'] ?? ($props['page_type'] ?? '')),
            'path' => mb_substr($path, 0, 2048),
            'referrer_host' => (string) (ChannelClassifier::host($referrer) ?? ''),
            'channel' => $channel,
            'utm_source' => mb_substr((string) ($utm['source'] ?? ''), 0, 200),
            'utm_medium' => mb_substr((string) ($utm['medium'] ?? ''), 0, 200),
            'utm_campaign' => mb_substr((string) ($utm['campaign'] ?? ''), 0, 500),
            'click_id_kind' => $clickKind,
            'device_type' => (string) ($device['type'] ?? ''),
            'os' => (string) ($device['os'] ?? ''),
            'browser' => (string) ($device['browser'] ?? ''),
            'country' => $geo['country'],
            'region' => $geo['region'],
            'city' => $geo['city'],
            'product_id' => (string) ($props['product_id'] ?? ''),
            'order_id' => (string) ($props['order_id'] ?? ($order['order_id'] ?? '')),
            'revenue' => $revenue,
            'currency' => $currency,
            'properties' => (object) $this->sanitizer->clean($event, $props),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $order
     * @param  array<string, mixed>  $props
     * @return array{country: string, region: string, city: string}
     */
    private function geo(?array $order, array $props): array
    {
        $src = [];
        if ($order !== null) {
            $ship = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
            $bill = is_array($order['billing'] ?? null) ? $order['billing'] : [];
            $src = ($ship['city'] ?? '') !== '' ? $ship : $bill;
        } elseif (is_array($props['billing'] ?? null)) {
            $src = $props['billing'];
        }

        return [
            'country' => strtoupper(mb_substr((string) ($src['country'] ?? ''), 0, 2)),
            'region' => mb_substr((string) ($src['state'] ?? ''), 0, 32),
            'city' => mb_substr((string) ($src['city'] ?? ''), 0, 120),
        ];
    }

    public static function chTime(string $iso): string
    {
        return CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s.v');
    }
}
