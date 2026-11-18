<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Http;

use App\Domain\Reviews\Actions\ModerateReview;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reseñas en la SPA (M15): cola de moderación, todas las reseñas con filtros,
 * resumen y solicitudes. Ver: viewDashboards. Aprobar, rechazar, responder:
 * manageMarketing (queda en la auditoría).
 */
final class ReviewController
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET reviews?status=&rating=&source=&media=&flagged=&product=&q=&page= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(Review::STATUSES)],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'source' => ['nullable', Rule::in(Review::SOURCES)],
            'media' => ['nullable', 'boolean'],
            'flagged' => ['nullable', 'boolean'],
            'product' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Review::query()
            ->when(isset($data['status']), fn (Builder $q) => $q->where('status', $data['status']))
            ->when(isset($data['rating']), fn (Builder $q) => $q->where('rating', (int) $data['rating']))
            ->when(isset($data['source']), fn (Builder $q) => $q->where('source', $data['source']))
            ->when($request->boolean('media'), fn (Builder $q) => $q->whereNotNull('media'))
            ->when($request->boolean('flagged'), fn (Builder $q) => $q->whereRaw("JSON_LENGTH(JSON_EXTRACT(ai_flags, '$.flags')) > 0"))
            ->when(isset($data['product']), fn (Builder $q) => $q->where('external_product_id', $data['product']))
            ->when(isset($data['q']) && $data['q'] !== '', function (Builder $q) use ($data) {
                $like = '%'.addcslashes((string) $data['q'], '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like)->orWhere('author_name', 'like', $like));
            });
        $total = (clone $query)->count();
        $page = (int) ($data['page'] ?? 1);
        // La cola se atiende de la más vieja a la más nueva; el resto, lo más reciente primero.
        $rows = $query->when(($data['status'] ?? null) === 'pending', fn (Builder $q) => $q->orderBy('created_at')->orderBy('id'), fn (Builder $q) => $q->orderByDesc('created_at')->orderByDesc('id'))
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        return response()->json([
            'data' => ReviewPresenter::many($rows, $this->canSeePii()),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE],
        ]);
    }

    /** GET reviews/summary?days=90 */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $days = max(7, min(365, (int) $request->query('days', '90')));
        $from = CarbonImmutable::now()->subDays($days);
        $byStatus = Review::query()->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $published = Review::query()->where('status', 'published');
        $dist = (clone $published)->selectRaw('rating, COUNT(*) AS c')->groupBy('rating')->pluck('c', 'rating');
        $count = (int) $dist->sum();
        $sum = 0;
        foreach ($dist as $rating => $c) {
            $sum += (int) $rating * (int) $c;
        }
        $requests = ReviewRequest::query()->where('created_at', '>=', $from)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $sent = (int) ($requests['sent'] ?? 0) + (int) ($requests['completed'] ?? 0);

        return response()->json([
            'reviews' => [
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'published' => (int) ($byStatus['published'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'flagged_pending' => Review::query()->where('status', 'pending')->whereRaw("JSON_LENGTH(JSON_EXTRACT(ai_flags, '$.flags')) > 0")->count(),
                'with_media' => (clone $published)->whereNotNull('media')->count(),
                'avg_rating' => $count > 0 ? round($sum / $count, 2) : null,
                'distribution' => array_combine(['5', '4', '3', '2', '1'], array_map(fn (int $s) => (int) ($dist[$s] ?? 0), [5, 4, 3, 2, 1])),
            ],
            'requests' => [
                'days' => $days,
                'scheduled' => (int) ($requests['scheduled'] ?? 0),
                'sent' => $sent,
                'completed' => (int) ($requests['completed'] ?? 0),
                'skipped' => (int) ($requests['skipped'] ?? 0) + (int) ($requests['expired'] ?? 0),
                'completion_rate' => $sent > 0 ? round((int) ($requests['completed'] ?? 0) / $sent, 4) : null,
            ],
        ]);
    }

    /** GET reviews/{review} */
    public function show(string $review): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(ReviewPresenter::one($this->find($review), $this->canSeePii()));
    }

    /** POST reviews/{review}/approve */
    public function approve(Request $request, string $review, ModerateReview $moderate): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $moderate->approve($this->current->get(), $this->find($review), (string) $request->user()?->getAuthIdentifier());

        return response()->json(ReviewPresenter::one($model->refresh(), $this->canSeePii()));
    }

    /** POST reviews/{review}/reject {reason} */
    public function reject(Request $request, string $review, ModerateReview $moderate): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['reason' => ['required', Rule::in(Review::REJECT_REASONS)]]);
        $model = $moderate->reject($this->current->get(), $this->find($review), $data['reason'], (string) $request->user()?->getAuthIdentifier());

        return response()->json(ReviewPresenter::one($model->refresh(), $this->canSeePii()));
    }

    /** PUT reviews/{review}/reply {text} */
    public function reply(Request $request, string $review, ModerateReview $moderate): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['text' => ['nullable', 'string', 'max:3000']]);
        $model = $moderate->reply($this->current->get(), $this->find($review), $data['text'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return response()->json(ReviewPresenter::one($model->refresh(), $this->canSeePii()));
    }

    /** POST reviews/bulk {action: approve|reject, ids[], reason?} */
    public function bulk(Request $request, ModerateReview $moderate): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string'],
            'reason' => ['required_if:action,reject', 'nullable', Rule::in(Review::REJECT_REASONS)],
        ]);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $done = 0;
        foreach ($data['ids'] as $id) {
            $review = Review::findByPublicId((string) $id);
            if ($review === null) {
                continue;
            }
            $data['action'] === 'approve'
                ? $moderate->approve($this->current->get(), $review, $userId)
                : $moderate->reject($this->current->get(), $review, (string) $data['reason'], $userId);
            $done++;
        }

        return response()->json(['updated' => $done]);
    }

    /** GET reviews/requests?status=&page= */
    public function requests(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $status = $request->query('status');
        $page = max(1, (int) $request->query('page', '1'));
        $query = ReviewRequest::query()->when(is_string($status) && $status !== '', fn (Builder $q) => $q->where('status', $status));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
        $orders = DB::table('orders')->where('workspace_id', $this->current->id())
            ->whereIn('id', $rows->pluck('order_id')->all())->pluck('number', 'id');

        return response()->json([
            'data' => $rows->map(fn (ReviewRequest $r) => [
                'id' => $r->publicId(),
                'status' => $r->status,
                'channel' => $r->channel === 'pending' ? null : $r->channel,
                'kind' => $r->kind,
                'skip_reason' => $r->skip_reason,
                'order' => ['id' => 'ord_'.$r->order_id, 'number' => (string) ($orders[$r->order_id] ?? '')],
                'contact_id' => 'ct_'.$r->contact_id,
                'products' => count($r->products ?? []),
                'scheduled_for' => $r->scheduled_for?->toIso8601String(),
                'sent_at' => $r->sent_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE],
        ]);
    }

    private function find(string $id): Review
    {
        return Review::findByPublicId($id) ?? abort(404);
    }

    private function canSeePii(): bool
    {
        return Gate::allows('ws.viewAllContacts');
    }
}
