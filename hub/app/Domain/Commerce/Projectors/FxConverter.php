<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Projectors;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tasa para convertir a la moneda del espacio de trabajo (fx_rates). Si no
 * hay tasa, devuelve null y el monto *_ws queda vacío hasta que exista.
 */
final class FxConverter
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function rate(string $from, string $to, CarbonInterface $date): ?string
    {
        if ($from === $to) {
            return '1';
        }
        $day = $date->toDateString();
        $key = $from.$to.$day;
        if (! array_key_exists($key, $this->cache)) {
            $rate = DB::table('fx_rates')
                ->where('base', $from)->where('quote', $to)->where('date', '<=', $day)
                ->orderByDesc('date')->value('rate');
            $this->cache[$key] = $rate === null ? null : (string) $rate;
        }

        return $this->cache[$key];
    }
}
