<?php

declare(strict_types=1);

namespace App\Domain\Flows;

use App\Domain\Flows\Console\DateTriggersCommand;
use App\Domain\Flows\Console\TickFlowsCommand;
use App\Domain\Flows\Listeners\RouteFlowTriggers;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Automatizaciones (modulos.md M09, hub/CLAUDE.md §7): TriggerRouter sobre la
 * ingesta, el scheduler de cada minuto y los disparadores por fecha cada hora.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventIngested::class, RouteFlowTriggers::class);

        if ($this->app->runningInConsole()) {
            $this->commands([TickFlowsCommand::class, DateTriggersCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:flows:tick')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('igr:flows:date-triggers')->hourlyAt(5)->withoutOverlapping()->onOneServer();
        });
    }
}
