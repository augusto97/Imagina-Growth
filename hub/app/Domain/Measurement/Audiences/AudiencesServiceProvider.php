<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences;

use App\Domain\Measurement\Audiences\Console\SyncAudiencesCommand;
use App\Domain\Measurement\Audiences\Listeners\RemoveOnAdsConsentRevoked;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Audiencias hacia anuncios (M06, Fase 3). Lo registra el proveedor de Medición. */
final class AudiencesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventIngested::class, RemoveOnAdsConsentRevoked::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAudiencesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Cada 12 h, fuera de las horas pico de los demás jobs nocturnos.
            $schedule->command('igr:audiences:sync')->twiceDailyAt(2, 14, 37)->withoutOverlapping()->onOneServer();
        });
    }
}
