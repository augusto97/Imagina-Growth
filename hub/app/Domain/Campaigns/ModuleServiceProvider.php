<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Console\CampaignsTickCommand;
use App\Domain\Campaigns\Console\WaRatesSeedCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Campañas (modulos.md M10): comandos y tareas programadas de hub/CLAUDE.md §15. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([CampaignsTickCommand::class, WaRatesSeedCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:campaigns:tick')->everyMinute()->withoutOverlapping()->onOneServer();
            // Meta cambia las tarifas el 1 de enero, abril, julio y octubre.
            $schedule->command('igr:wa-rates --check')->cron('17 9 2 1,4,7,10 *')->onOneServer();
        });
    }
}
