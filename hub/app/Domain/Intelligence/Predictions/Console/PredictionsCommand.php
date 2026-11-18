<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Console;

use App\Domain\Intelligence\Console\SelectsWorkspaces;
use App\Domain\Intelligence\Predictions\Actions\RunPredictions;
use App\Domain\Intelligence\Predictions\Jobs\RunPredictionsJob;
use Illuminate\Console\Command;

/**
 * `igr:predictions:train` (semanal: BG/NBD y Gamma-Gamma en el servicio Python más
 * todo lo demás) e `igr:predictions:nightly` (cada noche, sin reentrenar).
 */
final class PredictionsCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:predictions:train
        {--workspace=* : Espacios de trabajo (ws_… o ULID); por defecto, todos}
        {--only=* : Pasos: edno, lifetime, affinity, next_product, price_band, best_hour, engagement, propensity}
        {--nightly : Sin reentrenar BG/NBD (lo que corre cada noche)}
        {--queue : Encolar en lugar de correr aquí}';

    protected $description = 'Calcula las predicciones (próxima compra, abandono, CLV, afinidad, siguiente producto, rango de precio, mejor hora, interacción)';

    public function handle(RunPredictions $run): int
    {
        $only = array_values(array_filter((array) $this->option('only'), fn ($s) => in_array($s, RunPredictions::STEPS, true)));
        $train = ! (bool) $this->option('nightly');
        foreach ($this->workspaces() as $workspace) {
            if ((bool) $this->option('queue')) {
                RunPredictionsJob::dispatch($workspace->id, $train, $only);
                $this->line(__('Encolado: :ws', ['ws' => $workspace->publicId()]));

                continue;
            }
            $started = microtime(true);
            $result = $run->handle($workspace, $train, $only);
            $this->info(sprintf('%s · %s · %.1f s', $workspace->publicId(), collect($result)->map(fn ($s, $k) => "$k=$s")->implode(' '), microtime(true) - $started));
        }

        return self::SUCCESS;
    }
}
