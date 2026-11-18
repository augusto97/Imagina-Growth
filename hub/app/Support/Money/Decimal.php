<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Montos decimales en texto ("159900.00") sin pasar por float. */
final class Decimal
{
    /** @param int<0, max> $scale */
    public static function of(string|int|float|null $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }

        return (string) BigDecimal::of(is_float($value) ? (string) $value : $value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function add(string ...$values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $v) {
            $sum = $sum->plus(BigDecimal::of($v));
        }

        return (string) $sum->toScale(2, RoundingMode::HalfUp);
    }

    public static function sub(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)->minus(BigDecimal::of($b))->toScale(2, RoundingMode::HalfUp);
    }

    /** @param int<0, max> $scale */
    public static function mul(string $a, string $b, int $scale = 2): string
    {
        return (string) BigDecimal::of($a)->multipliedBy(BigDecimal::of($b))->toScale($scale, RoundingMode::HalfUp);
    }

    /** @param int<0, max> $scale */
    public static function div(string $a, int|string $b, int $scale = 2): string
    {
        return (string) BigDecimal::of($a)->dividedBy(BigDecimal::of((string) $b), $scale, RoundingMode::HalfUp);
    }

    public static function equals(string $a, string $b): bool
    {
        return BigDecimal::of($a)->isEqualTo(BigDecimal::of($b));
    }
}
