<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Reviews\Jobs\SyncReviewToSite;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\PublicReviewCache;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Moderación de reseñas (M15): aprobar (publica en los widgets y en WooCommerce),
 * rechazar (con un motivo de contenido, nunca por la calificación) y responder
 * como dueño. Cada acción queda en la auditoría y cambia los puntos pendientes de
 * la recompensa.
 */
final class ModerateReview
{
    public function __construct(private readonly RewardReview $rewards, private readonly RecordAuditLog $audit) {}

    public function approve(Workspace $workspace, Review $review, ?string $userId): Review
    {
        if ($review->status === 'published') {
            return $review;
        }
        $review->forceFill([
            'status' => 'published',
            'published_at' => $review->published_at ?? CarbonImmutable::now(),
            'reject_reason' => null,
            'moderated_by' => $userId,
            'moderated_at' => CarbonImmutable::now(),
        ])->save();
        $this->afterPublish($workspace, $review);
        if ($userId !== null) {
            $this->audit->handle('review.approved', $review, ['rating' => $review->rating], $workspace->id, userId: $userId);
        }

        return $review;
    }

    /** Lo que pasa al quedar publicada (también desde la moderación automática). */
    public function afterPublish(Workspace $workspace, Review $review): void
    {
        PublicReviewCache::bump($review->site_id);
        $this->rewards->onPublished($review);
        if (ReviewSettings::for($workspace)['publish_to_store'] && $review->source !== 'woocommerce' && $review->external_product_id !== null) {
            $review->forceFill(['sync_status' => 'pending'])->save();
            SyncReviewToSite::dispatch($workspace->id, $review->id, 'publish')->afterCommit();
        }
        // Una nativa de WooCommerce que se retiró y se vuelve a aprobar queda pendiente en la
        // tienda: no hay comando para aprobarla allá (se aprueba en WordPress).
    }

    public function reject(Workspace $workspace, Review $review, string $reason, ?string $userId): Review
    {
        if (! in_array($reason, Review::REJECT_REASONS, true)) {
            throw new InvalidArgumentException(__('Motivo de rechazo no válido.'));
        }
        $wasOnSite = $review->status === 'published' && $review->external_review_id !== null;
        $review->forceFill([
            'status' => 'rejected',
            'reject_reason' => $reason,
            'moderated_by' => $userId,
            'moderated_at' => CarbonImmutable::now(),
        ])->save();
        $this->rewards->onRejected($review);
        PublicReviewCache::bump($review->site_id);
        if ($wasOnSite) {
            $review->forceFill(['sync_status' => 'pending'])->save();
            SyncReviewToSite::dispatch($workspace->id, $review->id, 'unpublish')->afterCommit();
        }
        if ($userId !== null) {
            $this->audit->handle('review.rejected', $review, ['reason' => $reason, 'rating' => $review->rating], $workspace->id, userId: $userId);
        }

        return $review;
    }

    public function reply(Workspace $workspace, Review $review, ?string $text, string $userId): Review
    {
        $text = $text !== null ? trim($text) : null;
        $review->forceFill([
            'owner_reply' => $text === '' ? null : $text,
            'replied_at' => $text === null || $text === '' ? null : CarbonImmutable::now(),
        ])->save();
        PublicReviewCache::bump($review->site_id);
        if ($review->owner_reply !== null && $review->external_review_id !== null && $review->external_reply_id === null && $review->status === 'published') {
            SyncReviewToSite::dispatch($workspace->id, $review->id, 'reply')->afterCommit();
        }
        $this->audit->handle('review.replied', $review, ['length' => mb_strlen((string) $review->owner_reply)], $workspace->id, userId: $userId);

        return $review;
    }
}
