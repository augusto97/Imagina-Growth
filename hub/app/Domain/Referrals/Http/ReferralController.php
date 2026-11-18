<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Http;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Actions\RewardReferrer;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Referidos (modulos.md M14): lista con estados y señales de fraude, y la cola de
 * revisión manual. Ver: viewDashboards; aprobar o rechazar: manageMarketing.
 */
final class ReferralController
{
    /** GET referrals?status=&review=pending&page= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $request->validate([
            'status' => ['nullable', Rule::in(Referral::STATUSES)],
            'review' => ['nullable', Rule::in(['pending'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $query = Referral::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->input('review') === 'pending', fn ($q) => $q->where('review_status', Referral::PENDING_REVIEW))
            ->orderByDesc('created_at');
        $page = $query->paginate(50);
        /** @var list<Referral> $items */
        $items = $page->items();
        $names = ContactNames::for(array_values(array_filter(array_merge(
            array_map(fn (Referral $r) => $r->referrer_contact_id, $items),
            array_map(fn (Referral $r) => (string) $r->friend_contact_id, $items),
        ))));
        $orders = Order::query()->whereIn('id', array_values(array_filter(array_map(fn (Referral $r) => $r->order_id, $items))))->get(['id', 'number', 'external_id'])->keyBy('id');

        $counts = Referral::query()->select('status', DB::raw('COUNT(*) AS n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return response()->json([
            'data' => array_map(fn (Referral $r) => self::present($r, $names, $orders->get((string) $r->order_id)), $items),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => $counts,
            'pending_review' => Referral::query()->where('review_status', Referral::PENDING_REVIEW)->count(),
        ]);
    }

    /** POST referrals/{referral}/approve */
    public function approve(string $referral, RewardReferrer $reward, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($referral);
        abort_unless($model->review_status === Referral::PENDING_REVIEW, 409, __('Este referido no está en revisión.'));
        $model->forceFill(['review_status' => 'approved'])->save();
        $audit->handle('referrals.review_approved', $model, ['signals' => $model->signals()], $model->workspace_id);
        if ($model->status === Referral::QUALIFIED) {
            $reward->handle($model);
            $model->refresh();
        }

        return response()->json(self::present($model, ContactNames::for([$model->referrer_contact_id, (string) $model->friend_contact_id]), $model->order_id !== null ? Order::query()->find($model->order_id) : null));
    }

    /** POST referrals/{referral}/reject */
    public function reject(string $referral, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($referral);
        abort_unless($model->isOpen(), 409, __('Este referido ya se cerró.'));
        $model->forceFill(['review_status' => 'rejected', 'status' => Referral::REJECTED, 'rejected_reason' => 'manual_review', 'qualifies_at' => null])->save();
        $audit->handle('referrals.review_rejected', $model, ['signals' => $model->signals()], $model->workspace_id);

        return response()->json(self::present($model, ContactNames::for([$model->referrer_contact_id, (string) $model->friend_contact_id]), $model->order_id !== null ? Order::query()->find($model->order_id) : null));
    }

    /**
     * @param  array<string, array{id: string, name: string}>  $names
     * @return array<string, mixed>
     */
    public static function present(Referral $r, array $names, ?Order $order): array
    {
        return [
            'id' => $r->publicId(),
            'status' => $r->status,
            'review_status' => $r->review_status,
            'rejected_reason' => $r->rejected_reason,
            'code' => $r->code,
            'referrer' => $names[$r->referrer_contact_id] ?? null,
            'friend' => $r->friend_contact_id !== null ? ($names[$r->friend_contact_id] ?? null) : null,
            'signals' => $r->signals(),
            'order' => $order !== null ? ['id' => $order->publicId(), 'number' => (string) ($order->getAttribute('number') ?: $order->external_id)] : null,
            'order_amount_ws' => $r->order_amount_ws,
            'friend_coupon' => $r->friend_coupon_id !== null,
            'reward' => $r->reward !== null ? ['type' => $r->reward['type'] ?? null, 'state' => $r->reward['state'] ?? null, 'label' => $r->reward['label'] ?? null, 'error' => $r->reward['error'] ?? null] : null,
            'reward_cost_ws' => $r->reward_cost_ws,
            'ordered_at' => $r->ordered_at?->toIso8601String(),
            'qualifies_at' => $r->qualifies_at?->toIso8601String(),
            'rewarded_at' => $r->rewarded_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function find(string $publicId): Referral
    {
        return Referral::findByPublicId($publicId) ?? abort(404);
    }
}
