<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltySettlement;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\OrderPoints;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * Los puntos, el cashback y el saldo de un pedido a lo largo de su vida
 * (modulos.md M13, «Antifraude»):
 *
 * - `order.paid`: puntos **pendientes** (clave `order:{id}`); se confirma el
 *   débito del cupón de saldo que usó; se recalcula el nivel.
 * - `order.completed`: la confirmación queda para dentro de `pending_days`
 *   (periodo de devolución). Si la tienda nunca lo marca completado, se
 *   confirma igual 30 días después de pagado más ese periodo.
 * - Al confirmarse: los puntos pasan a disponibles y se acredita el cashback.
 * - `order.refunded`: revierte la parte proporcional (los pendientes; o, si ya
 *   se confirmaron, resta del saldo). Un reembolso total devuelve el saldo que
 *   se usó en el pedido.
 * - `order.cancelled` / `order.failed`: revierte lo pendiente y libera o
 *   devuelve el saldo.
 */
final class OrderLifecycle
{
    /** Días de respaldo si la tienda nunca marca el pedido como completado. */
    public const FALLBACK_DAYS = 30;

    public function __construct(
        private readonly DatabaseLoyaltyLedger $ledger,
        private readonly EvaluateTier $tiers,
        private readonly CurrentWorkspace $current,
    ) {}

    public function paid(Order $order): void
    {
        $contactId = $order->contact_id;
        if (! $order->is_paid || $contactId === null) {
            return;
        }
        $this->confirmWalletCoupons($order);
        $program = $this->ledger->program();
        if ($program !== null && $program->isActive()) {
            $rules = $program->rules();
            $tierId = Contact::query()->whereKey($contactId)->value('tier_id');
            $tier = is_string($tierId) ? LoyaltyTier::query()->whereKey($tierId)->first() : null;
            $points = OrderPoints::forOrder($order, $rules, $tier?->multiplier() ?? 1.0, $this->current->get()->timezone ?: 'America/Bogota');
            if ($points > 0) {
                $this->ledger->earnPoints($contactId, $points, 'order', 'order:'.$order->id, 'order', $order->id, pending: true);
            }
            $paidAt = CarbonImmutable::instance($order->paid_at ?? now());
            $due = $order->status === 'completed'
                ? CarbonImmutable::now()->addDays($rules->pendingDays)
                : $paidAt->addDays(self::FALLBACK_DAYS + $rules->pendingDays);
            $this->schedule($order, $due);
        }
        $this->tiers->handle($contactId);
    }

    public function completed(Order $order, CarbonImmutable $at): void
    {
        $program = $this->ledger->program();
        if ($program === null || $order->contact_id === null) {
            return;
        }
        $due = $at->addDays($program->rules()->pendingDays);
        $settlement = LoyaltySettlement::query()->where('ref_type', 'order')->where('ref_id', $order->id)->first();
        if ($settlement === null) {
            // Llegó completado sin pasar por pagado (contraentrega): se procesa como pagado primero.
            $this->paid($order);
            $settlement = LoyaltySettlement::query()->where('ref_type', 'order')->where('ref_id', $order->id)->first();
        }
        if ($settlement !== null && $settlement->status === 'scheduled' && $due->lt($settlement->due_at)) {
            $settlement->forceFill(['due_at' => $due])->save();
        }
        if ($settlement !== null && $settlement->status === 'scheduled' && $settlement->due_at->lte(now())) {
            $this->settle($settlement);
        }
    }

    /** Confirma (o revierte, si el pedido ya no vale) los puntos de un pedido al cumplirse su periodo. */
    public function settle(LoyaltySettlement $settlement): void
    {
        $order = Order::query()->find($settlement->ref_id);
        $valid = $order !== null && $order->is_paid && ! in_array($order->status, ['cancelled', 'refunded', 'failed'], true)
            && (float) $order->refunded < (float) $order->total;
        $this->ledger->settle('order', $settlement->ref_id, $valid);
        $contactId = $order?->contact_id;
        if ($valid && $contactId !== null) {
            $this->cashback($order);
            $this->tiers->handle($contactId);
        }
        $settlement->forceFill(['status' => $valid ? 'done' : 'cancelled'])->save();
    }

    public function refunded(Order $order, string $refundId, string $amount, bool $partial): void
    {
        $full = ! $partial || (float) $order->refunded >= (float) $order->total;
        $ratio = (float) $order->total > 0 ? min(1.0, (float) $amount / (float) $order->total) : 1.0;
        $earn = LedgerEntry::query()->where('idempotency_key', 'order:'.$order->id)->first();
        if ($earn !== null) {
            $settled = LedgerEntry::query()->where('idempotency_key', 'settle:'.$earn->id)->first();
            if ($settled === null) {
                if ($full) {
                    $this->ledger->settle('order', $order->id, false);
                    $this->cancelSettlement($order);
                } else {
                    $this->ledger->reversePending($earn, (int) round($earn->points() * $ratio), 'refund:'.$order->id.':'.$refundId);
                }
            } elseif ($settled->status === 'confirmed') {
                // Después del periodo de devolución: se resta del saldo (puede quedar en negativo).
                $already = -(int) LedgerEntry::query()->where('ref_type', 'order')->where('ref_id', $order->id)->where('reason', 'refund')->where('kind', 'points')->sum('delta');
                $points = $full ? $settled->points() - $already : min((int) round($earn->points() * $ratio), $settled->points() - $already);
                if ($points > 0) {
                    $this->ledger->debitPoints($settled->contact_id, $points, 'refund', 'refund:'.$order->id.':'.$refundId, 'order', $order->id, requireBalance: false, parentId: $settled->id);
                }
            }
        }
        $cashback = LedgerEntry::query()->where('idempotency_key', 'cashback:order:'.$order->id)->first();
        if ($cashback !== null) {
            $back = $full ? (string) $cashback->delta : Decimal::mul((string) $cashback->delta, (string) $ratio);
            $this->ledger->debitWallet($cashback->contact_id, $back, 'refund', 'cashback-refund:'.$order->id.':'.$refundId, 'order', $order->id, allowPartial: true);
        }
        if ($full) {
            $this->returnWallet($order);
        }
        $contactId = $order->contact_id;
        if ($contactId !== null) {
            $this->tiers->handle($contactId);
        }
    }

    public function cancelled(Order $order): void
    {
        $this->ledger->settle('order', $order->id, false);
        $this->cancelSettlement($order);
        $this->returnWallet($order);
        $contactId = $order->contact_id;
        if ($contactId !== null) {
            $this->tiers->handle($contactId);
        }
    }

    private function schedule(Order $order, CarbonImmutable $due): void
    {
        $existing = LoyaltySettlement::query()->where('ref_type', 'order')->where('ref_id', $order->id)->first();
        if ($existing === null) {
            LoyaltySettlement::query()->create(['ref_type' => 'order', 'ref_id' => $order->id, 'due_at' => $due, 'status' => 'scheduled']);
        }
    }

    private function cancelSettlement(Order $order): void
    {
        LoyaltySettlement::query()->where('ref_type', 'order')->where('ref_id', $order->id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** Cashback: un porcentaje del pedido como saldo a favor, cuando sus puntos se confirman. */
    private function cashback(Order $order): void
    {
        $program = $this->ledger->program();
        $contactId = $order->contact_id;
        if ($program === null || ! $program->isActive() || $contactId === null) {
            return;
        }
        $rules = $program->rules();
        if ((float) $rules->cashbackPct <= 0) {
            return;
        }
        $base = OrderPoints::baseAmount($order, $rules);
        $refundedRatio = (float) $order->total > 0 ? (float) $order->refunded / (float) $order->total : 0.0;
        $amount = Decimal::div(Decimal::mul(Decimal::mul($base, (string) max(0, 1 - $refundedRatio), 6), $rules->cashbackPct, 6), 100);
        if ((float) $amount > 0) {
            $this->ledger->creditWallet($contactId, $amount, 'cashback', 'cashback:order:'.$order->id, 'order', $order->id);
        }
    }

    /** El pedido usó cupones de saldo: su débito pendiente queda firme. */
    private function confirmWalletCoupons(Order $order): void
    {
        foreach ($this->walletCoupons($order) as $coupon) {
            $this->ledger->settle('coupon', $coupon->id, true);
        }
    }

    /** Pedido cancelado o reembolsado del todo: vuelve el saldo que usó (liberado si seguía pendiente). */
    private function returnWallet(Order $order): void
    {
        foreach ($this->walletCoupons($order) as $coupon) {
            $debit = LedgerEntry::query()->where('idempotency_key', 'wallet-redeem:'.$coupon->id)->first();
            if ($debit === null) {
                continue;
            }
            $confirmed = LedgerEntry::query()->where('idempotency_key', 'settle:'.$debit->id)->first();
            if ($confirmed === null) {
                $this->ledger->settle('coupon', $coupon->id, false);
            } elseif ($confirmed->status === 'confirmed') {
                $this->ledger->creditWallet($debit->contact_id, Decimal::of(ltrim((string) $confirmed->delta, '-')), 'refund', 'wallet-return:'.$coupon->id, 'order', $order->id);
            }
        }
    }

    /** @return list<CouponIssued> */
    private function walletCoupons(Order $order): array
    {
        $codes = array_values(array_filter(array_map(fn ($c) => strtoupper((string) $c), (array) ($order->coupon_codes ?? []))));
        if ($codes === []) {
            return [];
        }

        return array_values(CouponIssued::query()->where('site_id', $order->site_id)->where('source_type', 'wallet')->whereIn('code', $codes)->get()->all());
    }
}
