<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Support;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

/**
 * Puntos de un pedido: la base (total sin envío, o el total) en la moneda del
 * espacio de trabajo, repartida por ítem con el multiplicador de su categoría,
 * por el del nivel VIP y por el de la campaña de puntos activa al pagarse.
 */
final class OrderPoints
{
    /** Monto base del pedido en la moneda del espacio de trabajo. */
    public static function baseAmount(Order $order, ProgramRules $rules): string
    {
        $total = (string) $order->total;
        $totalWs = $order->total_ws !== null ? (string) $order->total_ws : $total;
        if ((float) $total <= 0) {
            return '0.00';
        }
        if ($rules->basis === 'total') {
            return Decimal::of($totalWs);
        }
        $rate = (float) $totalWs / (float) $total;
        $base = (float) $totalWs - (float) $order->getAttribute('shipping') * $rate;

        return number_format(max(0, $base), 2, '.', '');
    }

    public static function forOrder(Order $order, ProgramRules $rules, float $tierMultiplier, string $timezone): int
    {
        $base = (float) self::baseAmount($order, $rules);
        if ($base <= 0 || $rules->pointsPer <= 0) {
            return 0;
        }
        // Reparte la base por ítem según su peso en el pedido para aplicar el multiplicador de categoría.
        $items = OrderItem::query()->where('order_id', $order->id)->get(['line_total', 'category_ids']);
        $lines = (float) $items->sum(fn (OrderItem $i) => (float) $i->line_total);
        $weighted = $base;
        if ($lines > 0 && $rules->categoryMultipliers !== []) {
            $weighted = 0.0;
            foreach ($items as $item) {
                $share = $base * ((float) $item->line_total / $lines);
                $weighted += $share * $rules->categoryMultiplier(array_map('strval', (array) ($item->getAttribute('category_ids') ?? [])));
            }
        }
        $campaign = $rules->campaignMultiplier(CarbonImmutable::instance($order->paid_at ?? now()), $timezone);
        $points = ($weighted / (float) $rules->amountPer) * $rules->pointsPer * max(1.0, $tierMultiplier) * $campaign;

        return (int) floor($points + 1e-9);
    }
}
