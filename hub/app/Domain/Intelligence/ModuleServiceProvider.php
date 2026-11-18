<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Ai\AnthropicAiGateway;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Console\BuildKpiDailyCommand;
use App\Domain\Intelligence\Console\BuildSessionsCommand;
use App\Domain\Intelligence\Console\InsightsCommand;
use App\Domain\Intelligence\Console\NightlyCommand;
use App\Domain\Intelligence\Console\RfmCommand;
use App\Domain\Intelligence\Console\WeeklyReportCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Analítica e inteligencia de la Fase 1 (M05, M17): comandos y tareas
 * programadas de hub/CLAUDE.md §15.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fase 4: la única puerta hacia el modelo (hub/CLAUDE.md §12). Las pruebas usan FakeAiGateway::install().
        $this->app->singleton(AiGateway::class, AnthropicAiGateway::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([
            BuildSessionsCommand::class, BuildKpiDailyCommand::class, RfmCommand::class,
            InsightsCommand::class, NightlyCommand::class, WeeklyReportCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:sessions:build')->everyTenMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('igr:nightly')->dailyAt('02:30')->onOneServer();
            $schedule->command('igr:report:weekly --due')->hourlyAt(2)->onOneServer();
        });
    }
}
