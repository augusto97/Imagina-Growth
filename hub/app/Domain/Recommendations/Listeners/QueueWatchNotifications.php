<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Listeners;

use App\Domain\Recommendations\Jobs\NotifyProductWatchesJob;
use App\Ingest\Events\EventIngested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cuando el catálogo dice que un producto volvió a tener stock o bajó de
 * precio, encola la revisión de sus «avísame» (solo si alguien espera ese
 * producto: la consulta es por índice y no frena la ingesta). El barrido de
 * cada 10 minutos (`igr:watches:notify`) cubre lo que llegue por otra vía.
 */
final class QueueWatchNotifications
{
    public function handle(EventIngested $event): void
    {
        if (! in_array($event->event, ['catalog.stock_changed', 'catalog.price_changed', 'catalog.product_upserted'], true) || $event->siteId === '') {
            return;
        }
        $props = (array) ($event->envelope['properties'] ?? []);
        $productId = isset($props['product_id']) && (is_string($props['product_id']) || is_int($props['product_id'])) ? (string) $props['product_id'] : null;
        if ($productId === null) {
            return;
        }
        $relevant = match ($event->event) {
            'catalog.stock_changed' => in_array($props['to_status'] ?? null, ['instock', 'onbackorder'], true),
            'catalog.price_changed' => isset($props['to']) && (! isset($props['from']) || (float) $props['to'] < (float) $props['from']),
            default => true,
        };
        if (! $relevant) {
            return;
        }
        try {
            $waiting = DB::table('product_watches')->where('workspace_id', $event->workspaceId)->where('site_id', $event->siteId)
                ->where('external_product_id', $productId)->where('status', 'active')->exists();
            if ($waiting) {
                NotifyProductWatchesJob::dispatch($event->workspaceId, $event->siteId, $productId);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.watches.queue_failed', ['workspace' => $event->workspaceId]);
        }
    }
}
