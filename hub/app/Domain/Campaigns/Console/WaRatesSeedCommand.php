<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Console;

use App\Domain\Campaigns\Support\WaRateCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Carga la tabla de tarifas de WhatsApp (`wa_rates`) y avisa si está vieja. Meta
 * cambia las tarifas cada trimestre: la revisión corre el día 1 de enero, abril,
 * julio y octubre y deja un aviso en el log si no se actualizó.
 */
final class WaRatesSeedCommand extends Command
{
    protected $signature = 'igr:wa-rates {--seed : Carga las tarifas de WaRateCard} {--check : Solo revisa si están desactualizadas}';

    protected $description = 'Tarifas de WhatsApp por país y categoría para estimar el costo de las campañas';

    public function handle(): int
    {
        if ($this->option('seed')) {
            $rows = WaRateCard::seed();
            $this->info(__('Tarifas cargadas: :n filas (vigentes desde :date).', ['n' => $rows, 'date' => WaRateCard::EFFECTIVE_FROM]));
        }
        if (WaRateCard::isStale()) {
            $latest = WaRateCard::latestEffectiveFrom()?->toDateString() ?? '—';
            Log::warning('igr.wa_rates.outdated', ['latest' => $latest]);
            $this->warn(__('Las tarifas de WhatsApp son de :date: revisa la tabla de Meta (:url) y actualiza WaRateCard.', ['date' => $latest, 'url' => WaRateCard::SOURCE_URL]));

            return $this->option('check') ? self::FAILURE : self::SUCCESS;
        }
        $this->info(__('Tarifas al día.'));

        return self::SUCCESS;
    }
}
