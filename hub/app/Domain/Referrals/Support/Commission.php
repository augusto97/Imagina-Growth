<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Models\Affiliate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Comisión de un afiliado sobre un pedido: porcentaje del neto o del total, o un monto fijo. */
final class Commission
{
    /** @return array{amount: string, commission: string} en la moneda del espacio de trabajo */
    public static function for(Affiliate $affiliate, Order $order): array
    {
        $rule = $affiliate->commission;
        $basis = ($rule['basis'] ?? 'net') === 'total' ? OrderState::amountWs($order) : OrderState::netWs($order);
        $amount = BigDecimal::of($basis ?? '0')->toScale(2, RoundingMode::HalfUp);
        if ($amount->isNegative()) {
            $amount = BigDecimal::zero()->toScale(2);
        }
        $value = BigDecimal::of(is_numeric($rule['value'] ?? null) ? (string) $rule['value'] : '0');
        $commission = ($rule['type'] ?? 'percent') === 'fixed'
            ? $value->toScale(2, RoundingMode::HalfUp)
            : $amount->multipliedBy($value)->dividedBy(100, 2, RoundingMode::HalfUp);

        return ['amount' => (string) $amount, 'commission' => (string) $commission];
    }
}
