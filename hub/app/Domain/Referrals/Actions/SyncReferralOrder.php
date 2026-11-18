<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\Commission;
use App\Domain\Referrals\Support\OrderState;
use App\Domain\Referrals\Support\Programs;
use App\Domain\Referrals\Support\ProgramSettings;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Sigue el estado del pedido de un referido o de un afiliado (modulos.md M14):
 *
 * - Cancelado, fallido o reembolsado del todo → referido `rejected` y comisión `void`
 *   (una comisión ya pagada no se toca).
 * - Reembolso parcial → la comisión se recalcula y el referido se rechaza si quedó
 *   debajo de la compra mínima.
 * - Completado (o pagado, si el programa califica al pagar) → empieza el periodo de
 *   devolución: `qualifies_at`. La calificación la hace QualifyDueReferrals.
 *
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class SyncReferralOrder
{
    public function __construct(private readonly Programs $programs) {}

    public function handle(Order $order, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $settings = $this->programs->settings();
        $days = $settings?->returnDays() ?? (int) ProgramSettings::defaultRules()['return_days'];
        $onPaid = $settings?->qualifiesOnPaid() ?? false;

        $referral = Referral::query()->where('order_id', $order->id)->whereIn('status', [Referral::ORDERED, Referral::QUALIFIED])->first();
        if ($referral !== null) {
            $this->referral($referral, $order, $settings, $days, $onPaid, $now);
        }
        foreach (AffiliateConversion::query()->where('order_id', $order->id)->whereIn('status', ['pending', 'approved'])->get() as $conversion) {
            $this->conversion($conversion, $order, $days, $onPaid, $now);
        }
    }

    private function referral(Referral $referral, Order $order, ?ProgramSettings $settings, int $days, bool $onPaid, CarbonImmutable $now): void
    {
        $amount = OrderState::amountWs($order);
        $min = $settings?->minOrder();
        $changes = ['order_amount_ws' => $amount];
        if (OrderState::isVoid($order)) {
            $changes += ['status' => Referral::REJECTED, 'rejected_reason' => OrderState::voidReason($order), 'qualifies_at' => null];
        } elseif ($min !== null && $amount !== null && BigDecimal::of($amount)->isLessThan(BigDecimal::of($min))) {
            $changes += ['status' => Referral::REJECTED, 'rejected_reason' => 'below_minimum', 'qualifies_at' => null];
        } elseif ($referral->status === Referral::ORDERED && $referral->qualifies_at === null && OrderState::isComplete($order, $onPaid)) {
            $changes['qualifies_at'] = $now->addDays($days);
        }
        $referral->forceFill($changes)->save();
    }

    private function conversion(AffiliateConversion $conversion, Order $order, int $days, bool $onPaid, CarbonImmutable $now): void
    {
        if (OrderState::isVoid($order)) {
            $conversion->forceFill(['status' => 'void', 'void_reason' => OrderState::voidReason($order), 'qualifies_at' => null])->save();

            return;
        }
        $affiliate = Affiliate::query()->find($conversion->affiliate_id);
        $changes = [];
        if ($affiliate !== null) {
            $calc = Commission::for($affiliate, $order);
            $changes = ['amount_ws' => $calc['amount'], 'commission_ws' => $calc['commission']];
        }
        if ($conversion->status === 'pending' && $conversion->qualifies_at === null && OrderState::isComplete($order, $onPaid)) {
            $changes['qualifies_at'] = $now->addDays($days);
        }
        if ($changes !== []) {
            $conversion->forceFill($changes)->save();
        }
    }
}
