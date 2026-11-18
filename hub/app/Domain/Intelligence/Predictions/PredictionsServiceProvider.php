<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions;

use App\Domain\Intelligence\Predictions\Console\PredictionsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Predicciones (M17, Fase 4): comando y horarios (hub/CLAUDE.md §15). */
final class PredictionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([PredictionsCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Cada noche, después de igr:nightly (RFM a las 02:30); el reentrenamiento, los domingos.
            $schedule->command('igr:predictions:train --nightly --queue')->dailyAt('03:40')->onOneServer();
            $schedule->command('igr:predictions:train --queue')->weeklyOn(0, '04:20')->onOneServer();
        });
    }
}
