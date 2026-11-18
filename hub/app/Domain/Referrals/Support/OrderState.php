<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Commerce\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Lo que importa de un pedido para referidos y afiliados. */
final class OrderState
{
    public const VOID_STATUSES = ['cancelled', 'failed', 'refunded', 'trash'];

    /** Cancelado, fallido o reembolsado del todo. */
    public static function isVoid(Order $order): bool
    {
        if (in_array($order->status, self::VOID_STATUSES, true)) {
            return true;
        }
        $total = BigDecimal::of($order->total);

        return $total->isPositive() && BigDecimal::of($order->refunded)->isGreaterThanOrEqualTo($total);
    }

    public static function voidReason(Order $order): string
    {
        return match (true) {
            $order->status === 'cancelled' => 'order_cancelled',
            $order->status === 'failed' => 'order_failed',
            default => 'order_refunded',
        };
    }

    /** ¿Ya cuenta el periodo de devolución? Completado, o pagado si el programa califica al pagar. */
    public static function isComplete(Order $order, bool $onPaid): bool
    {
        return ! self::isVoid($order) && ($order->status === 'completed' || ($onPaid && $order->is_paid));
    }

    /** Total del pedido menos lo reembolsado, en la moneda del espacio de trabajo. */
    public static function amountWs(Order $order): ?string
    {
        if ($order->total_ws === null) {
            return null;
        }
        $total = BigDecimal::of($order->total);
        if ($total->isZero()) {
            return '0.00';
        }
        $kept = $total->minus(BigDecimal::of($order->refunded));

        return (string) BigDecimal::of($order->total_ws)->multipliedBy($kept)->dividedBy($total, 2, RoundingMode::HalfUp);
    }

    /** Neto (sin envío, impuestos ni reembolsos) en la moneda del espacio de trabajo. */
    public static function netWs(Order $order): ?string
    {
        $net = $order->getAttribute('net_ws');

        return $net === null ? null : (string) BigDecimal::of((string) $net)->toScale(2, RoundingMode::HalfUp);
    }

    /** Descuento del pedido en la moneda del espacio de trabajo (para el costo por referido). */
    public static function discountWs(Order $order): ?string
    {
        if ($order->total_ws === null) {
            return null;
        }
        $total = BigDecimal::of($order->total);
        $discount = BigDecimal::of((string) ($order->getAttribute('discount') ?? '0'));
        if ($total->isZero() || $discount->isZero()) {
            return '0.00';
        }

        return (string) BigDecimal::of($order->total_ws)->multipliedBy($discount)->dividedBy($total, 2, RoundingMode::HalfUp);
    }
}
