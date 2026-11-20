<?php

declare(strict_types=1);

namespace App\Support\Money;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tasas de cambio diarias contra el dólar (F11). Sirven para mostrar en la moneda del espacio de trabajo lo que se
 * estima en USD (costo de los mensajes); nunca para cobrar.
 *
 * La tasa de una moneda en un día es, en este orden:
 * 1. la manual del superadmin (`fx_rate_overrides`) si rige desde esa fecha o antes;
 * 2. la automática de ese día (`fx_rates` con base USD, `igr:fx:update`) o la última anterior (última tasa conocida si
 *    la fuente falló);
 * 3. si el día es anterior a la primera tasa guardada, la primera que hay.
 *
 * Sin ninguna, `null`: quien muestra el costo lo deja en USD y lo dice. Las tasas de cada moneda se cargan una vez por
 * instancia (son pocas filas: una por día), así un tablero con un año de días hace una consulta por moneda.
 */
final class ExchangeRates
{
    public const BASE = 'USD';

    /** @var array<string, array{dates: list<string>, rates: list<float>, sources: list<string>}> */
    private array $auto = [];

    /** @var array<string, array{rate: float, from: string, note: string|null, updated_at: string|null}|null> */
    private array $overrides = [];

    /**
     * Monedas que se actualizan (las de los espacios de trabajo menos el dólar).
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        $list = array_map(fn ($c) => strtoupper((string) $c), (array) config('igr.fx.currencies', []));

        return array_values(array_unique(array_filter($list, fn (string $c) => preg_match('/^[A-Z]{3}$/', $c) === 1 && $c !== self::BASE)));
    }

    /** Unidades de `currency` por 1 USD en la fecha (UTC), o null si no hay ninguna tasa. */
    public function perUsd(string $currency, CarbonInterface|string $date): ?float
    {
        return $this->resolve($currency, $date)['rate'] ?? null;
    }

    /** Convierte un monto entre dos monedas con la tasa de esa fecha, pasando por el dólar. null sin tasa. */
    public function convert(float $amount, string $from, string $to, CarbonInterface|string $date): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return $amount;
        }
        $f = $this->perUsd($from, $date);
        $t = $this->perUsd($to, $date);
        if ($f === null || $t === null || $f <= 0.0) {
            return null;
        }

        return $amount / $f * $t;
    }

    /**
     * Qué tasa rige para la moneda en la fecha (hoy por defecto) y de dónde sale.
     *
     * @return array{currency: string, rate: float|null, date: string|null, source: string|null, manual: bool, stale: bool}
     */
    public function describe(string $currency, ?CarbonInterface $on = null): array
    {
        $currency = strtoupper($currency);
        $on = CarbonImmutable::instance($on ?? CarbonImmutable::now('UTC'));
        $r = $this->resolve($currency, $on);
        $staleDays = max(1, (int) config('igr.fx.stale_after_days', 3));
        $stale = $currency !== self::BASE && ($r === null || (! $r['manual'] && $r['date'] !== null
            && CarbonImmutable::parse($r['date'], 'UTC')->addDays($staleDays)->lessThan($on->startOfDay())));

        return [
            'currency' => $currency,
            'rate' => $r['rate'] ?? null,
            'date' => $r['date'] ?? null,
            'source' => $r['source'] ?? null,
            'manual' => $r['manual'] ?? false,
            'stale' => $stale,
        ];
    }

    /**
     * Última tasa automática guardada de la moneda.
     *
     * @return array{rate: float, date: string, source: string}|null
     */
    public function latestAuto(string $currency): ?array
    {
        $auto = $this->autoRates(strtoupper($currency));
        $n = count($auto['dates']);

        return $n === 0 ? null : ['rate' => $auto['rates'][$n - 1], 'date' => $auto['dates'][$n - 1], 'source' => $auto['sources'][$n - 1]];
    }

    /** @return array{rate: float, from: string, note: string|null, updated_at: string|null}|null */
    public function override(string $currency): ?array
    {
        $currency = strtoupper($currency);
        if (! array_key_exists($currency, $this->overrides)) {
            $row = DB::table('fx_rate_overrides')->where('currency', $currency)->first();
            $this->overrides[$currency] = $row === null ? null : [
                'rate' => (float) $row->rate,
                'from' => substr((string) $row->effective_from, 0, 10),
                'note' => is_string($row->note) ? $row->note : null,
                'updated_at' => is_string($row->updated_at) ? CarbonImmutable::parse($row->updated_at, 'UTC')->toIso8601String() : null,
            ];
        }

        return $this->overrides[$currency];
    }

    /**
     * Guarda las tasas automáticas de un día (idempotente: el mismo día se reescribe).
     *
     * @param  array<string, float>  $rates  moneda → unidades por 1 USD
     */
    public static function store(string $date, array $rates, string $source): int
    {
        $rows = 0;
        foreach ($rates as $currency => $rate) {
            if ($rate <= 0.0 || ! is_finite($rate)) {
                continue;
            }
            DB::table('fx_rates')->updateOrInsert(
                ['date' => $date, 'base' => self::BASE, 'quote' => strtoupper($currency)],
                ['rate' => round($rate, 10), 'source' => mb_substr($source, 0, 40), 'fetched_at' => now()],
            );
            $rows++;
        }

        return $rows;
    }

    /** Olvida lo cargado (después de guardar tasas nuevas o una manual). */
    public function forget(): void
    {
        $this->auto = [];
        $this->overrides = [];
    }

    /** @return array{rate: float, date: string|null, source: string|null, manual: bool}|null */
    private function resolve(string $currency, CarbonInterface|string $date): ?array
    {
        $currency = strtoupper($currency);
        if ($currency === self::BASE) {
            return ['rate' => 1.0, 'date' => null, 'source' => null, 'manual' => false];
        }
        $day = $date instanceof CarbonInterface ? $date->copy()->setTimezone('UTC')->toDateString() : substr($date, 0, 10);
        $override = $this->override($currency);
        if ($override !== null && $override['from'] <= $day) {
            return ['rate' => $override['rate'], 'date' => $override['from'], 'source' => 'manual', 'manual' => true];
        }
        $auto = $this->autoRates($currency);
        if ($auto['dates'] === []) {
            return null;
        }
        // La última fecha <= el día (búsqueda binaria); si el día es anterior a todas, la primera.
        $lo = 0;
        $hi = count($auto['dates']) - 1;
        $found = 0;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($auto['dates'][$mid] <= $day) {
                $found = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return ['rate' => $auto['rates'][$found], 'date' => $auto['dates'][$found], 'source' => $auto['sources'][$found], 'manual' => false];
    }

    /** @return array{dates: list<string>, rates: list<float>, sources: list<string>} */
    private function autoRates(string $currency): array
    {
        if (! isset($this->auto[$currency])) {
            $rows = DB::table('fx_rates')->where('base', self::BASE)->where('quote', $currency)->orderBy('date')->get(['date', 'rate', 'source']);
            $this->auto[$currency] = [
                'dates' => array_values($rows->map(fn ($r) => substr((string) $r->date, 0, 10))->all()),
                'rates' => array_values($rows->map(fn ($r) => (float) $r->rate)->all()),
                'sources' => array_values($rows->map(fn ($r) => is_string($r->source) ? $r->source : 'manual')->all()),
            ];
        }

        return $this->auto[$currency];
    }
}
