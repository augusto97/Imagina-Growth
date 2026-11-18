<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\ReviewMediaStore;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reseña para la SPA. Los medios van con URL firmada (la moderación ve también
 * las pendientes). Quien no puede ver datos de contactos (rol `viewer`) recibe
 * enmascarado el texto de las reseñas marcadas con datos personales.
 */
final class ReviewPresenter
{
    /**
     * @param  Collection<int, Review>  $reviews
     * @return list<array<string, mixed>>
     */
    public static function many(Collection $reviews, bool $canSeePii): array
    {
        $ws = app(CurrentWorkspace::class)->id();
        $products = DB::table('products')->where('workspace_id', $ws)->whereIn('id', $reviews->pluck('product_id')->filter()->unique()->values()->all())
            ->get(['id', 'name', 'image_url'])->keyBy('id');
        $orders = DB::table('orders')->where('workspace_id', $ws)->whereIn('id', $reviews->pluck('order_id')->filter()->unique()->values()->all())
            ->get(['id', 'number', 'external_id'])->keyBy('id');

        return array_values($reviews->map(fn (Review $r) => self::one($r, $canSeePii, $products->get((string) $r->product_id), $orders->get((string) $r->order_id)))->all());
    }

    /** @return array<string, mixed> */
    public static function one(Review $r, bool $canSeePii, ?\stdClass $product = null, ?\stdClass $order = null): array
    {
        if ($product === null && $r->product_id !== null) {
            $product = DB::table('products')->where('workspace_id', $r->workspace_id)->where('id', $r->product_id)->first(['id', 'name', 'image_url']);
        }
        if ($order === null && $r->order_id !== null) {
            $order = DB::table('orders')->where('workspace_id', $r->workspace_id)->where('id', $r->order_id)->first(['id', 'number', 'external_id']);
        }
        $flags = $r->flagKeys();
        $mask = ! $canSeePii && in_array('personal_data', $flags, true);

        return [
            'id' => $r->publicId(),
            'status' => $r->status,
            'rating' => $r->rating,
            'title' => $mask && $r->title !== null ? Pii::scrub($r->title) : $r->title,
            'body' => $mask && $r->body !== null ? Pii::scrub($r->body) : $r->body,
            'author' => $r->author_name,
            'verified' => $r->verified_buyer,
            'source' => $r->source,
            'product' => $r->external_product_id === null ? null : [
                'id' => $r->external_product_id,
                'name' => $product !== null ? (string) ($product->name ?? '') : null,
                'image' => $product !== null && is_string($product->image_url ?? null) && $product->image_url !== '' ? $product->image_url : null,
            ],
            'contact_id' => $r->contact_id !== null ? 'ct_'.$r->contact_id : null,
            'order' => $order !== null ? ['id' => 'ord_'.$order->id, 'number' => (string) ($order->number ?? $order->external_id ?? '')] : null,
            'media' => ReviewMediaStore::present($r, signed: true),
            'flags' => $flags,
            'flag_details' => (array) ($r->ai_flags['details'] ?? []),
            'reply' => $r->owner_reply,
            'replied_at' => $r->replied_at?->toIso8601String(),
            'reject_reason' => $r->reject_reason,
            'sync_status' => $r->sync_status,
            'sync_error' => $r->sync_error,
            'on_store' => $r->external_review_id !== null,
            'created_at' => $r->created_at?->toIso8601String(),
            'published_at' => $r->published_at?->toIso8601String(),
            'moderated_at' => $r->moderated_at?->toIso8601String(),
        ];
    }
}
