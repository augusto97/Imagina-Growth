<?php

declare(strict_types=1);

namespace App\Domain\Onsite;

use App\Domain\Onsite\Console\AbWinnersCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** En el sitio (M12): widgets de captación, quiz y su A/B. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AbWinnersCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:onsite:ab-winners')->dailyAt('04:20')->withoutOverlapping()->onOneServer();
        });
    }
}
