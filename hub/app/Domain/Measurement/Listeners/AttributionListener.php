<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Listeners;

use App\Domain\Measurement\Jobs\AttributeOrder;
use App\Ingest\Events\EventIngested;

/** Encola la atribución de cada order.paid en vivo con las pistas del sobre (sin datos personales). */
final class AttributionListener
{
    public function handle(EventIngested $event): void
    {
        if ($event->event !== 'order.paid') {
            return;
        }
        $orderId = $event->envelope['properties']['order']['order_id'] ?? null;
        if (! is_string($orderId) || $event->siteId === '') {
            return;
        }
        $ctx = is_array($event->envelope['context'] ?? null) ? $event->envelope['context'] : [];
        $clean = fn ($touch) => is_array($touch) ? array_intersect_key($touch, array_flip(['utm', 'click_ids', 'referrer', 'landing', 'at', 'referral_code'])) : null;

        AttributeOrder::dispatch($event->workspaceId, $event->siteId, $orderId, [
            'first_touch' => $clean($ctx['first_touch'] ?? null),
            'last_touch' => $clean($ctx['last_touch'] ?? (isset($ctx['utm']) || isset($ctx['click_ids']) || isset($ctx['referral_code']) ? ['utm' => $ctx['utm'] ?? [], 'click_ids' => $ctx['click_ids'] ?? [], 'referrer' => $ctx['page']['referrer'] ?? null, 'referral_code' => $ctx['referral_code'] ?? null] : null)),
            'anonymous_id' => is_string($event->envelope['identity']['anonymous_id'] ?? null) ? $event->envelope['identity']['anonymous_id'] : null,
        ]);
    }
}
