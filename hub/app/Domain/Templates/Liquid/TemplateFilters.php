<?php

declare(strict_types=1);

namespace App\Domain\Templates\Liquid;

use Carbon\CarbonImmutable;
use Keepsuit\Liquid\Filters\FiltersProvider;
use NumberFormatter;
use Throwable;
use Traversable;

/**
 * Filtros propios de las plantillas (además de los estándar de Liquid):
 *
 * - `limit: 3`: los primeros N elementos de una lista (`recomendaciones.para_ti | limit: 3`).
 * - `moneda`: «$ 282.000» con la moneda y la configuración regional de la tienda
 *   (`moneda: "MXN"` para forzar otra). COP, CLP y VES sin decimales.
 * - `fecha`: «18 de noviembre de 2026» (`fecha: "corta"` → «18/11/2026»).
 */
final class TemplateFilters extends FiltersProvider
{
    private const ZERO_DECIMALS = ['COP', 'CLP', 'PYG', 'JPY', 'VES'];

    /** @return list<mixed> */
    public function limit(mixed $input, mixed $count = 3): array
    {
        $items = $input instanceof Traversable ? iterator_to_array($input, false) : (is_array($input) ? array_values($input) : []);

        return array_slice($items, 0, max(0, (int) $count));
    }

    public function moneda(mixed $input, ?string $currency = null): string
    {
        if ($input === null || $input === '' || ! is_numeric($input)) {
            return is_scalar($input) ? (string) $input : '';
        }
        $register = $this->context->getRegister('igr');
        $currency = $currency ?? (is_array($register) ? (string) ($register['currency'] ?? 'COP') : 'COP');
        $locale = is_array($register) ? (string) ($register['locale'] ?? 'es_CO') : 'es_CO';

        return self::money((float) $input, $currency, $locale);
    }

    /** El monto como lo muestra `moneda` (también los ejemplos del selector de variables). */
    public static function money(float $amount, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        $digits = in_array($currency, self::ZERO_DECIMALS, true) ? 0 : 2;
        $formatter = new NumberFormatter(str_replace('-', '_', $locale), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $formatted = $formatter->formatCurrency($amount, $currency);

        return $formatted === false ? number_format($amount, $digits, ',', '.') : str_replace("\u{00A0}", ' ', $formatted);
    }

    public function fecha(mixed $input, string $format = 'larga'): string
    {
        if ($input === null || $input === '') {
            return '';
        }
        $register = $this->context->getRegister('igr');
        $tz = is_array($register) ? (string) ($register['timezone'] ?? 'America/Bogota') : 'America/Bogota';
        $raw = is_scalar($input) ? (string) $input : '';
        try {
            // Una fecha sin hora es un día del calendario: no se corre de zona horaria.
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? CarbonImmutable::parse($raw, $tz) : CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return $raw;
        }
        $local = $date->setTimezone($tz)->locale('es');
        if (! $local instanceof CarbonImmutable) {
            return $date->format('d/m/Y');
        }

        return $format === 'corta' ? $local->format('d/m/Y') : $local->isoFormat('D [de] MMMM [de] YYYY');
    }
}
