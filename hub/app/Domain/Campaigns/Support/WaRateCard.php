<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tarifas de WhatsApp por mensaje entregado (cumplimiento.md §5.1), en USD, por mercado y categoría. Las cobra Meta al
 * cliente; aquí solo sirven para estimar el costo de una campaña y de cada mensaje enviado. Viven en `wa_rates`
 * (`country` = el mercado), se cargan con `igr:wa-rates --seed` (y las migraciones) y se revisan cada trimestre: Meta
 * cambia las tarifas solo el 1 de enero, abril, julio y octubre.
 *
 * Fuente: tabla oficial en USD de Meta («USD list rates», https://developers.facebook.com/docs/whatsapp/pricing,
 * revisada el 20 nov 2026), vigente desde el 1 de octubre de 2026:
 * - Colombia: marketing 0,0125; utilidad, autenticación y servicio 0,0008.
 * - México: marketing 0,0397 (subió desde 0,0305 el 1 oct 2026); el resto 0,0085.
 * - Resto de América Latina (`LA`; Venezuela, Ecuador, Panamá, Bolivia, Costa Rica, República Dominicana, El Salvador,
 *   Guatemala, Haití, Honduras, Jamaica, Nicaragua, Paraguay, Puerto Rico y Uruguay): marketing 0,074; el resto 0,0113.
 * - Mercados propios cercanos: Argentina, Brasil, Chile, Perú y Norteamérica (`NA`: Estados Unidos y Canadá).
 * - «Otros» (`ZZ`): marketing 0,0604; el resto 0,0077.
 *
 * Servicio y utilidad dentro de la ventana de 24 h: gratis del 1 jul 2025 al 30 sep 2026; desde el 1 oct 2026 Meta
 * los cobra a la tarifa de utilidad publicada, salvo los primeros 1.000 mensajes de servicio de cada número al mes
 * (MetaCloudDriver::estimateCost lleva esa cuenta). Los mensajes dentro de la ventana gratuita de un anuncio de clic a
 * WhatsApp (hasta 7 días) también son gratis, pero el hub todavía no la distingue: se estiman con la tarifa normal.
 */
final class WaRateCard
{
    public const SOURCE_URL = 'https://developers.facebook.com/docs/whatsapp/pricing';

    /** Vigencia de la tabla más reciente. */
    public const EFFECTIVE_FROM = '2026-10-01';

    /** Un trimestre y unos días: más viejo que esto, la tabla está desactualizada. */
    public const STALE_AFTER_DAYS = 100;

    /** Mercados de Meta que agrupan varios países. */
    public const REST_OF_LATAM = 'LA';

    public const NORTH_AMERICA = 'NA';

    public const OTHER = 'ZZ';

    /** Países que Meta cobra como «Resto de América Latina» (sus códigos de llamada, verificados en la misma página). */
    public const REST_OF_LATAM_COUNTRIES = ['BO', 'CR', 'DO', 'EC', 'SV', 'GT', 'HT', 'HN', 'JM', 'NI', 'PA', 'PY', 'PR', 'UY', 'VE'];

    /**
     * Tablas por fecha de vigencia: mercado → categoría → USD por mensaje. La de 2025 se deja para la historia (en ella
     * `VE` era el resto de América Latina y el servicio era gratis).
     *
     * @var array<string, array<string, array<string, float>>>
     */
    public const TABLES = [
        '2025-07-01' => [
            'CO' => ['marketing' => 0.0125, 'utility' => 0.0008, 'authentication' => 0.0008, 'service' => 0.0],
            'MX' => ['marketing' => 0.0305, 'utility' => 0.0085, 'authentication' => 0.0085, 'service' => 0.0],
            'VE' => ['marketing' => 0.0740, 'utility' => 0.0113, 'authentication' => 0.0113, 'service' => 0.0],
            'LA' => ['marketing' => 0.0740, 'utility' => 0.0113, 'authentication' => 0.0113, 'service' => 0.0],
            'ZZ' => ['marketing' => 0.0604, 'utility' => 0.0077, 'authentication' => 0.0077, 'service' => 0.0],
        ],
        '2026-10-01' => [
            'CO' => ['marketing' => 0.0125, 'utility' => 0.0008, 'authentication' => 0.0008, 'service' => 0.0008],
            'MX' => ['marketing' => 0.0397, 'utility' => 0.0085, 'authentication' => 0.0085, 'service' => 0.0085],
            'LA' => ['marketing' => 0.0740, 'utility' => 0.0113, 'authentication' => 0.0113, 'service' => 0.0113],
            'AR' => ['marketing' => 0.0618, 'utility' => 0.0260, 'authentication' => 0.0260, 'service' => 0.0260],
            'BR' => ['marketing' => 0.0625, 'utility' => 0.0068, 'authentication' => 0.0068, 'service' => 0.0068],
            'CL' => ['marketing' => 0.0889, 'utility' => 0.0200, 'authentication' => 0.0200, 'service' => 0.0200],
            'PE' => ['marketing' => 0.0703, 'utility' => 0.0300, 'authentication' => 0.0300, 'service' => 0.0300],
            'NA' => ['marketing' => 0.0250, 'utility' => 0.0034, 'authentication' => 0.0034, 'service' => 0.0034],
            'ZZ' => ['marketing' => 0.0604, 'utility' => 0.0077, 'authentication' => 0.0077, 'service' => 0.0077],
        ],
    ];

    /** Mensajes de servicio gratis por número de WhatsApp al mes desde el 1 oct 2026. */
    public const FREE_SERVICE_PER_NUMBER = 1000;

    public const FREE_SERVICE_FROM = '2026-10-01';

    /**
     * La tabla más reciente (la que se carga con una fecha a elección en las pruebas).
     *
     * @return array<string, array<string, float>>
     */
    public static function current(): array
    {
        return self::TABLES[self::EFFECTIVE_FROM];
    }

    /**
     * Carga (o actualiza) la tabla. Sin fecha, todas las tablas en su vigencia; con fecha, la más reciente en esa fecha.
     * Devuelve las filas escritas.
     */
    public static function seed(?string $effectiveFrom = null): int
    {
        $tables = $effectiveFrom === null ? self::TABLES : [$effectiveFrom => self::current()];
        $rows = 0;
        foreach ($tables as $date => $markets) {
            foreach ($markets as $market => $categories) {
                foreach ($categories as $category => $rate) {
                    DB::table('wa_rates')->updateOrInsert(
                        ['country' => $market, 'category' => $category, 'effective_from' => $date],
                        ['rate_usd' => $rate, 'source_url' => self::SOURCE_URL],
                    );
                    $rows++;
                }
            }
        }

        return $rows;
    }

    /** Mercado de Meta para un país (código ISO de dos letras). */
    public static function market(string $country): string
    {
        $country = strtoupper(trim($country));

        return match (true) {
            in_array($country, self::REST_OF_LATAM_COUNTRIES, true) => self::REST_OF_LATAM,
            in_array($country, ['US', 'CA'], true) => self::NORTH_AMERICA,
            default => $country,
        };
    }

    /**
     * Tarifa vigente en `on` para el país: la de su mercado (o del país en tablas viejas) y, si no hay, la de «otros».
     * null si la tabla está vacía.
     */
    public static function rate(string $country, string $category = 'marketing', ?CarbonImmutable $on = null): ?float
    {
        $on = ($on ?? CarbonImmutable::now())->toDateString();
        $country = strtoupper(trim($country));
        foreach (array_unique([self::market($country), $country, self::OTHER]) as $code) {
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
