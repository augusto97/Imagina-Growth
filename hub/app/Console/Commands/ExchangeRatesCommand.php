<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Money\ExchangeRates;
use App\Support\Money\UpdateExchangeRates;
use Illuminate\Console\Command;

/**
 * Tasas de cambio del día (F11): las trae de la fuente de `config/igr.php` (`fx`) y las guarda en `fx_rates` (base USD).
 * Corre dos veces al día (la fuente se actualiza una vez, cerca de las 00:00 UTC); si falla, rige la última tasa
 * conocida y Plataforma e igr:doctor lo avisan.
 */
final class ExchangeRatesCommand extends Command
{
    protected $signature = 'igr:fx:update';

    protected $description = 'Trae las tasas de cambio del día contra el dólar';

    public function handle(UpdateExchangeRates $update, ExchangeRates $rates): int
    {
        $status = $update->handle();
        if (! $status['ok']) {
            $this->warn(__('No se actualizaron las tasas: :error Rige la última tasa conocida.', ['error' => (string) $status['error']]));

            return self::FAILURE;
        }
        $rates->forget();
        foreach (ExchangeRates::currencies() as $currency) {
            $d = $rates->describe($currency);
            $this->line(sprintf('%s: %s (%s, %s)', $currency, $d['rate'] === null ? '—' : (string) $d['rate'], $d['date'] ?? '—', $d['manual'] ? __('manual') : ($d['source'] ?? '—')));
        }
        if ($status['missing'] !== []) {
            $this->warn(__('La fuente no trajo: :list. Rige su última tasa conocida.', ['list' => implode(', ', $status['missing'])]));
        }

        return self::SUCCESS;
    }
}
