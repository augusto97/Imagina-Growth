<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

/** Montos para textos en español (COP sin decimales, como la SPA). */
final class Money
{
    private const ZERO_DECIMAL = ['COP', 'CLP', 'PYG', 'JPY', 'VES'];

    public static function format(float|int|string|null $amount, string $currency): string
    {
        $value = (float) ($amount ?? 0);
        $decimals = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;

        return '$'.number_format($value, $decimals, ',', '.');
    }

    public static function percent(float $ratio, int $decimals = 1): string
    {
        return number_format($ratio * 100, $decimals, ',', '.').' %';
    }

    public static function number(float|int $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }
}
