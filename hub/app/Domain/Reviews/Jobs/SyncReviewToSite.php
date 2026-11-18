<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Jobs;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\ReviewMediaStore;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lleva la moderación a WooCommerce con los comandos firmados de la Fase 3
 * (api-connector.md §6):
 * - `publish`: `review.publish` crea la reseña nativa (comprador verificado y
 *   medios como URLs públicas /r/m/…) y guarda su `comment_id` como
 *   `external_review_id`. Es idempotente en el plugin por `review_id`.
 * - `unpublish`: `review.unpublish` la deja pendiente en la tienda.
 * - `reply`: `review.reply` publica la respuesta del dueño como comentario hijo.
 *
 * Las reseñas de la tienda (sin producto) y las que vinieron de WooCommerce no
 * se publican: no hay a dónde o ya están allá. Reintenta 5 veces (1, 5, 15, 60
 * y 180 minutos) y deja `sync_status = failed` con el error.
 */
final class SyncReviewToSite implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public string $workspaceIdValue, public string $reviewId, public string $action) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(SendSiteCommand $command): void
    {
        $review = Review::query()->find($this->reviewId);
        if ($review === null) {
            return;
        }
        $site = Site::query()->find($review->site_id);
        if ($site === null || ! $site->isActive() || $site->platform !== 'woocommerce') {
            $review->forceFill(['sync_status' => 'skipped', 'sync_error' => null])->save();

            return;
        }

        try {
            match ($this->action) {
                'publish' => $this->publish($command, $site, $review),
                'unpublish' => $this->unpublish($command, $site, $review),
                'reply' => $this->reply($command, $site, $review),
                default => null,
            };
        } catch (RuntimeException $e) {
            $review->forceFill(['sync_status' => 'failed', 'sync_error' => mb_substr($e->getMessage(), 0, 300)])->save();

            throw $e; // se reintenta con el backoff
        }
    }

    private function publish(SendSiteCommand $command, Site $site, Review $review): void
    {
        if ($review->status !== 'published' || $review->external_product_id === null || $review->source === 'woocommerce') {
            $review->forceFill(['sync_status' => 'skipped'])->save();

            return;
        }
        $email = $review->contact_id !== null ? Contact::query()->find($review->contact_id)?->email : null;
        $orderExternal = $review->order_id !== null ? DB::table('orders')->where('id', $review->order_id)->value('external_id') : null;
        $media = array_map(fn (array $m) => array_filter($m, fn ($v) => $v !== null), ReviewMediaStore::present($review));
        $data = $command->handle($site, 'review.publish', array_filter([
            'review_id' => $review->publicId(),
            'product_id' => $review->external_product_id,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => (string) ($review->body ?? ''),
            'author_name' => $review->author_name ?? __('Cliente'),
            'author_email' => $email,
            'verified' => $review->verified_buyer,
            'media' => $media,
            'created_at' => $review->created_at?->toIso8601String(),
            'order_id' => is_string($orderExternal) ? $orderExternal : null,
        ], fn ($v) => $v !== null), timeout: 15);
        $commentId = isset($data['comment_id']) ? (string) $data['comment_id'] : null;

        DB::transaction(function () use ($review, $commentId): void {
            if ($commentId !== null) {
                // Si review.submitted de ese comentario llegó antes que la respuesta, sobra esa copia.
                Review::query()->where('site_id', $review->site_id)->where('external_review_id', $commentId)
                    ->where('source', 'woocommerce')->whereKeyNot($review->id)->delete();
            }
            try {
                $review->forceFill(['external_review_id' => $commentId, 'sync_status' => 'synced', 'sync_error' => null])->save();
            } catch (UniqueConstraintViolationException) {
                $review->forceFill(['sync_status' => 'synced', 'sync_error' => null])->save();
            }
        });

        if ($review->owner_reply !== null && $review->external_reply_id === null && $commentId !== null) {
            $this->reply($command, $site, $review);
        }
    }

    private function unpublish(SendSiteCommand $command, Site $site, Review $review): void
    {
        if ($review->external_review_id === null) {
            return;
        }
        $command->handle($site, 'review.unpublish', ['comment_id' => $review->external_review_id], timeout: 15);
        $review->forceFill(['sync_status' => 'synced', 'sync_error' => null])->save();
    }

    private function reply(SendSiteCommand $command, Site $site, Review $review): void
    {
        if ($review->external_review_id === null || $review->owner_reply === null || $review->external_reply_id !== null) {
            return;
        }
        $workspace = Workspace::query()->findOrFail($review->workspace_id);
        $data = $command->handle($site, 'review.reply', [
            'comment_id' => $review->external_review_id,
            'body' => $review->owner_reply,
            'author_name' => ReviewSettings::for($workspace)['reply_author'] ?? $workspace->name,
        ], timeout: 15);
        $review->forceFill([
            'external_reply_id' => isset($data['comment_id']) ? (string) $data['comment_id'] : null,
            'sync_status' => 'synced', 'sync_error' => null,
        ])->save();
    }
}
