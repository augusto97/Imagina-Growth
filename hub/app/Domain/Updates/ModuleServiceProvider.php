<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Billing\Http\EnsurePlatformAdmin;
use App\Domain\Updates\Console\CheckUpdatesCommand;
use App\Domain\Updates\Http\UpdateController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Actualización con un clic desde el panel de la plataforma (docs/despliegue.md §5): detecta la versión
 * nueva en GitHub Releases cada hora y deja el pedido para growth-update. Solo superadmin con 2FA.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::prefix('api/platform/updates')->middleware(['api', 'auth:sanctum', EnsurePlatformAdmin::class])
                ->group(function (): void {
                    Route::get('/', [UpdateController::class, 'show']);
                    Route::post('check', [UpdateController::class, 'check'])->middleware('throttle:6,1');
                    Route::post('install', [UpdateController::class, 'install'])->middleware('throttle:10,1');
                });
        }

        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([CheckUpdatesCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('growth:updates:check')->hourlyAt(7)->withoutOverlapping()->onOneServer();
        });
    }
}
