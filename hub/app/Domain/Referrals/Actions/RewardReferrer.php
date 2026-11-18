<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\OrderState;
use App\Domain\Referrals\Support\Programs;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recompensa de quien refiere (modulos.md M14), una sola vez por referido:
 *
 * 1. Con el referido bloqueado: sigue `qualified`, no está en revisión y no pasó
 *    el límite mensual (si lo pasó, `rejected` con `monthly_limit`). Se marca
 *    «entregando» antes de llamar a nadie.
 * 2. Entrega puntos o saldo (LoyaltyLedger, idempotente por `referral:{id}`) o un
 *    cupón (se busca primero el que ya se creó para ese referido).
 * 3. `rewarded`, con el costo del referido: descuento del amigo + saldo o cupón fijo.
 *
 * Si algo falla (sin programa de puntos, el sitio no responde) queda `qualified` con
 * el error en `reward.error` y QualifyDueReferrals lo reintenta.
 */
final class RewardReferrer
{
    public function __construct(
        private readonly Programs $programs,
        private readonly LoyaltyLedger $ledger,
        private readonly CouponIssuer $coupons,
        private readonly CurrentWorkspace $current,
    ) {}

    public function handle(Referral $referral, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $settings = $this->programs->settings();
        if ($settings === null) {
            return false;
        }
        $type = (string) $settings->referrer['type'];
        $claimed = DB::transaction(function () use ($referral, $settings, $type, $now): bool {
            /** @var Referral|null $locked */
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== Referral::QUALIFIED || $locked->review_status === Referral::PENDING_REVIEW || $locked->review_status === 'rejected') {
                return false;
            }
            if (($locked->reward['state'] ?? null) !== 'issuing' && $this->overMonthlyLimit($locked, $settings, $now)) {
                $locked->forceFill(['status' => Referral::REJECTED, 'rejected_reason' => 'monthly_limit'])->save();

                return false;
            }
            $locked->forceFill(['reward' => ['type' => $type, 'state' => 'issuing']])->save();

            return true;
        });
        $referral->refresh();
        if (! $claimed) {
            return false;
        }

        $key = 'referral:'.$referral->id;
        $reward = ['type' => $type, 'state' => 'done', 'label' => $settings->referrerRewardLabel()];
        $ledgerId = null;
        try {
            if ($type === 'points') {
                $points = max(1, (int) $settings->referrer['points']);
                $ledgerId = $this->ledger->earnPoints($referral->referrer_contact_id, $points, 'referral', $key, 'referral', $referral->id);
                if ($ledgerId === null) {
                    return $this->failed($referral, $type, 'loyalty_inactive');
                }
                $reward['points'] = $points;
            } elseif ($type === 'wallet') {
                $amount = Decimal::of((string) ($settings->referrer['amount'] ?? '0'));
                $ledgerId = $this->ledger->creditWallet($referral->referrer_contact_id, $amount, 'referral', $key, 'referral', $referral->id);
                $reward['amount'] = $amount;
            } else {
                $coupon = CouponIssued::query()->where('source_type', 'referral')->where('source_id', $referral->id)
                    ->where('contact_id', $referral->referrer_contact_id)->where('status', 'active')->first()
                    ?? $this->coupons->issue($settings->referrerCoupon($referral->referrer_contact_id, $referral->id));
                $reward['coupon_id'] = $coupon->id;
                $reward['coupon_code'] = $coupon->code;
            }
        } catch (CouponIssueException $e) {
            return $this->failed($referral, $type, 'coupon_failed');
        }
        if ($ledgerId !== null) {
            $reward['ledger_id'] = $ledgerId;
        }

        $referral->forceFill([
            'status' => Referral::REWARDED,
            'rewarded_at' => $now,
            'reward' => $reward,
            'reward_ledger_id' => $ledgerId,
            'reward_cost_ws' => $this->cost($referral, $settings),
        ])->save();

        return true;
    }

    private function failed(Referral $referral, string $type, string $error): bool
    {
        Log::warning('igr.referrals.reward_failed', ['workspace' => $this->current->id(), 'referral' => $referral->id, 'error' => $error]);
        $referral->forceFill(['reward' => ['type' => $type, 'state' => 'failed', 'error' => $error]])->save();

        return false;
    }

    private function overMonthlyLimit(Referral $referral, ProgramSettings $settings, CarbonImmutable $now): bool
    {
        $limit = $settings->monthlyLimit();
        if ($limit === null) {
            return false;
        }
        $tz = $this->current->get()->timezone ?: 'America/Bogota';
        $start = $now->setTimezone($tz)->startOfMonth()->utc();

        return Referral::query()->where('referrer_contact_id', $referral->referrer_contact_id)->where('status', Referral::REWARDED)
            ->where('rewarded_at', '>=', $start)->count() >= $limit;
    }

    /** Descuento que usó el amigo más el valor en dinero de la recompensa (los puntos no tienen precio fijo). */
    private function cost(Referral $referral, ProgramSettings $settings): ?string
    {
        $order = $referral->order_id !== null ? Order::query()->find($referral->order_id) : null;
        $discount = $order !== null && $referral->friend_coupon_id !== null ? OrderState::discountWs($order) : null;
        $reward = $settings->referrerRewardValue();
        if ($discount === null && $reward === null) {
            return null;
        }

        return Decimal::add($discount ?? '0', $reward ?? '0');
    }
}
