<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Listeners;

use App\Domain\Reviews\Actions\ProjectStoreReview;
use App\Domain\Reviews\Jobs\ScheduleOrderFollowUps;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Escucha la ingesta en vivo (M15):
 * - `order.completed` y `order.paid` → programa la solicitud de reseña y las
 *   encuestas NPS y CSAT del pedido (job en la cola `default`).
 * - `review.submitted` → proyecta la reseña nativa de WooCommerce en `reviews`.
 *
 * La importación histórica no publica EventIngested (hub/CLAUDE.md §5): las
 * reseñas importadas se proyectan con `igr:reviews:project-store` (ver
 * ProjectStoreReviewsCommand) y los pedidos viejos no generan solicitudes.
 */
final class ReviewEventsListener
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ProjectStoreReview $projector) {}

    public function handle(EventIngested $event): void
    {
        if ($event->event === 'order.completed' || $event->event === 'order.paid') {
            $orderId = $event->envelope['properties']['order']['order_id'] ?? $event->envelope['properties']['order_id'] ?? null;
            if (is_scalar($orderId) && (string) $orderId !== '' && $event->siteId !== '') {
                ScheduleOrderFollowUps::dispatch($event->workspaceId, $event->siteId, (string) $orderId, $event->event === 'order.paid' ? 'paid' : 'completed')->afterCommit();
            }

            return;
        }
        if ($event->event !== 'review.submitted') {
            return;
        }
        $site = Site::query()->find($event->siteId);
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($site === null || $workspace === null) {
            return;
        }
        $this->current->run($workspace, fn () => $this->projector->handle($site, $event->envelope, $event->contactId));
    }
}
