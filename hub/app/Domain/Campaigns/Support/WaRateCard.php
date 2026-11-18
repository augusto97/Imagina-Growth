<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tarifas de WhatsApp por mensaje entregado (cumplimiento.md §5.1), en USD, por
 * país y categoría. Las cobra Meta al cliente; aquí solo sirven para estimar el
 * costo de una campaña. Se cargan en `wa_rates` con `igr:wa-rates:seed` y se
 * revisan cada trimestre (Meta cambia las tarifas el 1 de enero, abril, julio y
 * octubre).
 *
 * **[verificar]** Los valores son los de la tabla de Meta vigente desde el
 * 1 de julio de 2025 (precio por mensaje). Venezuela está en «resto de
 * Latinoamérica» y `ZZ` es «otros». Los mensajes de servicio quedan en 0 hasta
 * confirmar la tarifa que Meta cobra desde el 1 de octubre de 2026.
 */
final class WaRateCard
{
    public const SOURCE_URL = 'https://developers.facebook.com/docs/whatsapp/pricing';

    public const EFFECTIVE_FROM = '2025-07-01';

    /** Un trimestre y unos días: más viejo que esto, la tabla está desactualizada. */
    public const STALE_AFTER_DAYS = 100;

    /** @var array<string, array<string, float>> país → categoría → USD por mensaje */
    public const RATES = [
        'CO' => ['marketing' => 0.0125, 'utility' => 0.0008, 'authentication' => 0.0008, 'service' => 0.0],
        'MX' => ['marketing' => 0.0305, 'utility' => 0.0085, 'authentication' => 0.0085, 'service' => 0.0],
        'VE' => ['marketing' => 0.0740, 'utility' => 0.0113, 'authentication' => 0.0113, 'service' => 0.0],
        'ZZ' => ['marketing' => 0.0604, 'utility' => 0.0077, 'authentication' => 0.0077, 'service' => 0.0],
    ];

    /** Carga (o actualiza) la tabla. Devuelve las filas escritas. */
    public static function seed(string $effectiveFrom = self::EFFECTIVE_FROM): int
    {
        $rows = 0;
        foreach (self::RATES as $country => $categories) {
            foreach ($categories as $category => $rate) {
                DB::table('wa_rates')->updateOrInsert(
                    ['country' => $country, 'category' => $category, 'effective_from' => $effectiveFrom],
                    ['rate_usd' => $rate, 'source_url' => self::SOURCE_URL],
                );
                $rows++;
            }
        }

        return $rows;
    }

    /** Tarifa vigente en `on` para el país (o «otros»). null si la tabla está vacía. */
    public static function rate(string $country, string $category = 'marketing', ?CarbonImmutable $on = null): ?float
    {
        $on = ($on ?? CarbonImmutable::now())->toDateString();
        foreach ([strtoupper($country), 'ZZ'] as $code) {
            $rate = DB::table('wa_rates')->where('country', $code)->where('category', $category)
                ->where('effective_from', '<=', $on)->orderByDesc('effective_from')->value('rate_usd');
            if ($rate !== null) {
                return (float) $rate;
            }
        }

        return null;
    }

    /** Fecha de la tarifa más reciente, o null si no hay. */
    public static function latestEffectiveFrom(): ?CarbonImmutable
    {
        $date = DB::table('wa_rates')->max('effective_from');

        return is_string($date) ? CarbonImmutable::parse($date) : null;
    }

    public static function isStale(?CarbonImmutable $now = null): bool
    {
        $latest = self::latestEffectiveFrom();

        return $latest === null || $latest->addDays(self::STALE_AFTER_DAYS)->lessThan($now ?? CarbonImmutable::now());
    }
}
