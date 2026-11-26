<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Billing\Http\EnsurePlatformAdmin;
use App\Domain\Campaigns\Console\CampaignsTickCommand;
use App\Domain\Campaigns\Console\WaRatesSeedCommand;
use App\Domain\Campaigns\Http\PlatformCalendarController;
use App\Domain\Platform\Http\RequirePlatformTwoFactor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Campañas (modulos.md M10): comandos y tareas programadas de hub/CLAUDE.md §15, y las excepciones del calendario
 * comercial en Plataforma (/api/platform/calendar, solo superadmin con 2FA).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::prefix('api/platform')->middleware(['api', 'auth:sanctum', EnsurePlatformAdmin::class, RequirePlatformTwoFactor::class])
                ->group(function (): void {
                    Route::get('calendar', [PlatformCalendarController::class, 'index']);
                    Route::put('calendar/{country}/{key}/{year}', [PlatformCalendarController::class, 'update'])->where(['key' => '[a-z0-9_]+', 'year' => '[0-9]{4}']);
                    Route::delete('calendar/{country}/{key}/{year}', [PlatformCalendarController::class, 'destroy'])->where(['key' => '[a-z0-9_]+', 'year' => '[0-9]{4}']);
                });
        }
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
