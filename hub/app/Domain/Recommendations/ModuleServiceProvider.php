<?php

declare(strict_types=1);

namespace App\Domain\Recommendations;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Recommendations\Console\BuildRecommendationsCommand;
use App\Domain\Recommendations\Console\NotifyWatchesCommand;
use App\Domain\Recommendations\Engine\RecommendationEngine;
use App\Domain\Recommendations\Listeners\QueueWatchNotifications;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Comercio en el sitio (modulos.md M16): motor de recomendaciones, «avísame»
 * de stock y precio, y banners personalizados por segmento.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecommendationService::class, RecommendationEngine::class);
    }

    public function boot(): void
    {
        Event::listen(EventIngested::class, QueueWatchNotifications::class);

        if ($this->app->runningInConsole()) {
            $this->commands([BuildRecommendationsCommand::class, NotifyWatchesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Reglas de «comprados juntos» (pesado) cada noche; popularidad y tendencia cada 3 horas.
            $schedule->command('igr:reco:build')->dailyAt('03:41')->withoutOverlapping()->onOneServer();
            $schedule->command('igr:reco:build --stats-only')->cron('17 */3 * * *')->withoutOverlapping()->onOneServer();
            // Avisos aplazados por la política de envío o sin evento del catálogo.
            $schedule->command('igr:watches:notify')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
