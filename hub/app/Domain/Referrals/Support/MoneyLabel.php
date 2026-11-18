<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use NumberFormatter;

/** «$20.000» en la moneda y el idioma del espacio de trabajo, para los textos de recompensas. */
final class MoneyLabel
{
    private const ZERO_DECIMALS = ['COP', 'CLP', 'PYG'];

    public static function format(string $amount, string $currency, string $locale = 'es-CO'): string
    {
        $currency = strtoupper($currency);
        $digits = in_array($currency, self::ZERO_DECIMALS, true) ? 0 : 2;
        $formatter = new NumberFormatter(str_replace('-', '_', $locale), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $formatted = $formatter->formatCurrency((float) $amount, $currency);

        return $formatted === false ? number_format((float) $amount, $digits, ',', '.') : str_replace("\u{00A0}", ' ', $formatted);
    }
}
