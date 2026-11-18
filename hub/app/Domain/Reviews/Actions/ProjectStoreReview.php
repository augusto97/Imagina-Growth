<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\PublicReviewCache;
use App\Domain\Sites\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Proyecta `review.submitted` (reseñas nativas de WooCommerce, en vivo y de la
 * importación histórica) en `reviews` con `source = woocommerce`. El evento no
 * trae el texto ni el autor (eventos.md §4.2): la reseña cuenta para el promedio
 * y las insignias; el tema de la tienda la sigue mostrando con su texto.
 *
 * No duplica las que publicó el hub: si el comentario ya es de una reseña
 * propia (`external_review_id`), no se toca. Idempotente por (sitio, comentario).
 */
final class ProjectStoreReview
{
    /** @param  array<string, mixed>  $envelope */
    public function handle(Site $site, array $envelope, ?string $contactId): ?Review
    {
        $p = is_array($envelope['properties'] ?? null) ? $envelope['properties'] : [];
        $commentId = isset($p['review_id']) ? (string) $p['review_id'] : '';
        $productId = isset($p['product_id']) ? (string) $p['product_id'] : '';
        $rating = (int) ($p['rating'] ?? 0);
        if ($commentId === '' || $productId === '' || $rating < 1 || $rating > 5) {
            return null;
        }
        $status = match ((string) ($p['status'] ?? 'approved')) {
            'approved' => 'published',
            'pending' => 'pending',
            default => 'rejected',
        };
        $at = self::date($p['created_at'] ?? null) ?? self::date($envelope['occurred_at'] ?? null) ?? CarbonImmutable::now();

        $existing = Review::query()->where('site_id', $site->id)->where('external_review_id', $commentId)->first();
        if ($existing !== null) {
            if ($existing->source !== 'woocommerce') {
                return $existing; // es una reseña del hub publicada en la tienda
            }
            $existing->forceFill([
                'rating' => $rating,
                'status' => $status,
                'reject_reason' => $status === 'rejected' ? ((string) ($p['status'] ?? '') === 'spam' ? 'spam' : 'other') : null,
                'published_at' => $status === 'published' ? ($existing->published_at ?? $at) : $existing->published_at,
                'verified_buyer' => (bool) ($p['verified_owner'] ?? $existing->verified_buyer),
                'contact_id' => $existing->contact_id ?? $contactId,
            ])->save();
            PublicReviewCache::bump($site->id);

            return $existing;
        }

        $internal = DB::table('products')->where('site_id', $site->id)->where('external_id', $productId)->value('id');
        try {
            $review = new Review;
            $review->forceFill([
                'workspace_id' => $site->workspace_id,
                'site_id' => $site->id,
                'product_id' => is_string($internal) ? $internal : null,
                'external_product_id' => $productId,
                'contact_id' => $contactId,
                'rating' => $rating,
                'status' => $status,
                'reject_reason' => $status === 'rejected' ? ((string) ($p['status'] ?? '') === 'spam' ? 'spam' : 'other') : null,
                'published_at' => $status === 'published' ? $at : null,
                'verified_buyer' => (bool) ($p['verified_owner'] ?? false),
                'source' => 'woocommerce',
                'external_review_id' => $commentId,
                'sync_status' => 'skipped',
                'created_at' => $at,
            ])->save();
            PublicReviewCache::bump($site->id);

            return $review;
        } catch (UniqueConstraintViolationException) {
            return Review::query()->where('site_id', $site->id)->where('external_review_id', $commentId)->first();
        }
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
