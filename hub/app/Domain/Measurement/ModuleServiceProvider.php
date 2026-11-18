<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use App\Domain\Measurement\Audiences\AudiencesServiceProvider;
use App\Domain\Measurement\Console\AttributionBackfillCommand;
use App\Domain\Measurement\Console\ImportAdSpendCommand;
use App\Domain\Measurement\Listeners\AttributionListener;
use App\Domain\Measurement\Listeners\ConversionRouter;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Medición (M06): conversiones desde el servidor, atribución de canales y gasto en anuncios. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Audiencias (Fase 3): su propio proveedor dentro de Medición.
        $this->app->register(AudiencesServiceProvider::class);
    }

    public function boot(): void
    {
        Event::listen(EventIngested::class, ConversionRouter::class);
        Event::listen(EventIngested::class, AttributionListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AttributionBackfillCommand::class, ImportAdSpendCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Gasto: las plataformas cierran el día con retraso y ajustan cifras; se reimportan 3 días.
            $schedule->command('igr:ad-spend:import --days=3')->dailyAt('05:17')->withoutOverlapping()->onOneServer();
            // Pedidos importados o que llegaron sin atribución.
            $schedule->command('igr:attribution:backfill')->dailyAt('04:23')->withoutOverlapping()->onOneServer();
        });
    }
}
