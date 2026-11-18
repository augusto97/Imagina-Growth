<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Support;

use NumberFormatter;

/** Montos para textos del programa («$20.000»): sin decimales en COP y CLP. */
final class MoneyText
{
    private const ZERO_DECIMALS = ['COP', 'CLP', 'PYG', 'JPY'];

    public static function format(string|float $amount, string $currency, string $locale = 'es-CO'): string
    {
        $digits = in_array(strtoupper($currency), self::ZERO_DECIMALS, true) ? 0 : 2;
        $formatter = new NumberFormatter(str_replace('-', '_', $locale), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $text = $formatter->formatCurrency((float) $amount, strtoupper($currency));

        return $text === false ? number_format((float) $amount, $digits, ',', '.') : str_replace("\u{00A0}", ' ', $text);
    }

    public static function points(int $points, string $locale = 'es-CO'): string
    {
        $formatter = new NumberFormatter(str_replace('-', '_', $locale), NumberFormatter::DECIMAL);
        $text = $formatter->format($points);

        return $text === false ? (string) $points : str_replace("\u{00A0}", ' ', $text);
    }
}
