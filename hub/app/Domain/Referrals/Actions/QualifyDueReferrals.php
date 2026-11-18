<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\OrderState;
use App\Domain\Referrals\Support\ReferralEvents;
use Carbon\CarbonImmutable;

/**
 * Lo que pasó el periodo de devolución (cada hora, por espacio de trabajo):
 *
 * - Referidos `ordered` con `qualifies_at` vencido y fuera de revisión: se revisa el
 *   pedido una última vez → `qualified` (emite `referral.converted`) → recompensa.
 * - Referidos `qualified` sin recompensa (falló antes o se aprobó en revisión): reintento.
 * - Comisiones `pending` con `qualifies_at` vencido → `approved`.
 * - Pedidos completados sin `qualifies_at` (el evento llegó antes que la atribución):
 *   se sincronizan.
 *
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class QualifyDueReferrals
{
    public function __construct(
        private readonly SyncReferralOrder $sync,
        private readonly RewardReferrer $reward,
        private readonly ReferralEvents $events,
    ) {}

    /** @return array{qualified: int, rewarded: int, approved: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['qualified' => 0, 'rewarded' => 0, 'approved' => 0];

        // Pedidos completados que aún no empiezan su periodo de devolución.
        $missing = Referral::query()->where('status', Referral::ORDERED)->whereNull('qualifies_at')->whereNotNull('order_id')->limit(500)->pluck('order_id')
            ->merge(AffiliateConversion::query()->where('status', 'pending')->whereNull('qualifies_at')->limit(500)->pluck('order_id'))
            ->unique()->all();
        foreach (Order::query()->whereIn('id', $missing)->get() as $order) {
            $this->sync->handle($order, $now);
        }

        $due = Referral::query()->where('status', Referral::ORDERED)->where('qualifies_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('review_status')->orWhere('review_status', 'approved'))->limit(500)->get();
        foreach ($due as $referral) {
            $order = $referral->order_id !== null ? Order::query()->find($referral->order_id) : null;
            if ($order === null) {
                continue;
            }
            $this->sync->handle($order, $now);
            $referral->refresh();
            if ($referral->status !== Referral::ORDERED || OrderState::isVoid($order)) {
                continue;
            }
            $referral->forceFill(['status' => Referral::QUALIFIED])->save();
            $this->events->converted($referral, $order);
            $counts['qualified']++;
        }

        $toReward = Referral::query()->where('status', Referral::QUALIFIED)
            ->where(fn ($q) => $q->whereNull('review_status')->orWhere('review_status', 'approved'))->limit(500)->get();
        foreach ($toReward as $referral) {
            if ($this->reward->handle($referral, $now)) {
                $counts['rewarded']++;
            }
        }

        foreach (AffiliateConversion::query()->where('status', 'pending')->where('qualifies_at', '<=', $now)->limit(1000)->get() as $conversion) {
            $order = Order::query()->find($conversion->order_id);
            if ($order !== null) {
                $this->sync->handle($order, $now);
                $conversion->refresh();
            }
            if ($conversion->status === 'pending') {
                $conversion->forceFill(['status' => 'approved', 'approved_at' => $now])->save();
                $counts['approved']++;
            }
        }

        return $counts;
    }
}
