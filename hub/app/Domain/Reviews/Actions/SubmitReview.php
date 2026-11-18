<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Reviews\Contracts\ReviewClassifier;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\InvalidReviewMedia;
use App\Domain\Reviews\Support\ReviewMediaStore;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Guarda la reseña que la persona dejó en /r/{token} (M15). El contacto y el
 * pedido salen del token, así que es de comprador verificado.
 *
 * 1. Una reseña por producto y solicitud (si se envía dos veces, vale la primera).
 * 2. Procesa las fotos y el video (ReviewMediaStore: sin metadatos).
 * 3. Pasa el texto por el filtro (ReviewClassifier). Con moderación automática se
 *    publica si no tiene banderas, sea cual sea la calificación; si no, queda
 *    pendiente en la cola.
 * 4. Marca la solicitud como completada, emite `review.collected` (sin el texto)
 *    y deja los puntos de la recompensa (pendientes hasta que se publique).
 */
final class SubmitReview
{
    public function __construct(
        private readonly ReviewMediaStore $media,
        private readonly ReviewClassifier $classifier,
        private readonly RewardReview $rewards,
        private readonly ModerateReview $moderation,
        private readonly HubEvents $hubEvents,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function handle(Workspace $workspace, ReviewRequest $request, ?string $productId, int $rating, ?string $title, ?string $body, string $authorName, array $files = []): Review
    {
        $lock = Cache::lock('igr:review-submit:'.$request->id.':'.($productId ?? 'store'), 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['rating' => __('Ya estamos guardando tu reseña.')]);
        }
        try {
            $existing = Review::query()->where('request_id', $request->id)
                ->when($productId === null, fn ($q) => $q->whereNull('external_product_id'), fn ($q) => $q->where('external_product_id', $productId))
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->create($workspace, $request, $productId, $rating, $title, $body, $authorName, $files);
        } finally {
            $lock->release();
        }
    }

    /** @param  list<UploadedFile>  $files */
    private function create(Workspace $workspace, ReviewRequest $request, ?string $productId, int $rating, ?string $title, ?string $body, string $authorName, array $files): Review
    {
        $settings = ReviewSettings::for($workspace);
        $order = DB::table('orders')->where('id', $request->order_id)->first(['id', 'site_id', 'external_id']);
        $siteId = (string) ($request->site_id ?? $order->site_id ?? '');
        $reviewId = strtoupper((string) Str::ulid());

        $media = [];
        try {
            $videos = 0;
            foreach ($files as $file) {
                $stored = $this->media->store($file, $workspace->id, $reviewId);
                $videos += $stored['type'] === 'video' ? 1 : 0;
                if ($videos > (int) config('igr-reviews.uploads.max_videos', 1)) {
                    throw new InvalidReviewMedia(__('Solo puedes subir un video.'));
                }
                $media[] = $stored;
            }
        } catch (InvalidReviewMedia $e) {
            $this->discard($workspace->id, $reviewId);

            throw ValidationException::withMessages(['media' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->discard($workspace->id, $reviewId);

            throw $e;
        }

        $flags = $this->classifier->classify($title, $body, $authorName);
        $publish = $settings['moderation']['mode'] === 'auto' && $flags['flags'] === [];
        $now = CarbonImmutable::now();
        $internalProduct = $productId !== null
            ? DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $siteId)->where('external_id', $productId)->value('id')
            : null;

        try {
            $review = DB::transaction(function () use ($workspace, $request, $reviewId, $siteId, $productId, $internalProduct, $rating, $title, $body, $authorName, $media, $flags, $publish, $now): Review {
                $review = new Review;
                $review->forceFill([
                    'id' => $reviewId,
                    'workspace_id' => $workspace->id,
                    'site_id' => $siteId,
                    'product_id' => is_string($internalProduct) ? $internalProduct : null,
                    'external_product_id' => $productId,
                    'contact_id' => $request->contact_id,
                    'order_id' => $request->order_id,
                    'request_id' => $request->id,
                    'rating' => $rating,
                    'title' => $title,
                    'body' => $body,
                    'author_name' => $authorName,
                    'media' => $media === [] ? null : $media,
                    'status' => $publish ? 'published' : 'pending',
                    'published_at' => $publish ? $now : null,
                    'verified_buyer' => true,
                    'source' => 'request',
                    'ai_flags' => $flags,
                ])->save();
                ReviewRequest::query()->whereKey($request->id)->whereNull('completed_at')
                    ->update(['status' => 'completed', 'completed_at' => $now, 'updated_at' => $now]);

                return $review;
            });
        } catch (Throwable $e) {
            $this->discard($workspace->id, $reviewId);

            throw $e;
        }

        $this->hubEvents->emit($workspace, 'review.collected', [
            'review_id' => $review->publicId(),
            'product_id' => $productId,
            'order_id' => is_string($order?->external_id) ? $order->external_id : null,
            'rating' => $rating,
            'has_media' => $media !== [],
            'media_count' => count($media),
            'source' => 'request',
            'status' => $review->status,
        ], $request->contact_id, Site::query()->find($siteId));

        $this->rewards->onSubmitted($review);
        if ($publish) {
            $this->moderation->afterPublish($workspace, $review);
        }

        return $review;
    }

    /** Nombre que se publica: «Ana R.» (nombre e inicial del apellido). */
    public static function defaultAuthor(?Contact $contact): string
    {
        $first = trim((string) $contact?->first_name);
        $last = trim((string) $contact?->last_name);
        if ($first === '') {
            return __('Cliente verificado');
        }
        $first = mb_convert_case(mb_strtolower(explode(' ', $first)[0]), MB_CASE_TITLE);

        return $last !== '' ? $first.' '.mb_strtoupper(mb_substr($last, 0, 1)).'.' : $first;
    }

    private function discard(string $workspaceId, string $reviewId): void
    {
        $this->media->disk()->deleteDirectory(ReviewMediaStore::dir($workspaceId, $reviewId));
    }
}
