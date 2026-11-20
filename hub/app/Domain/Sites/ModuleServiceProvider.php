<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Domain\Sites\Console\SiteHealthCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Sitios conectados: el semáforo de salud cada 10 minutos (docs/auditoria-2026-11.md F6). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([SiteHealthCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:sites:health')->everyTenMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
