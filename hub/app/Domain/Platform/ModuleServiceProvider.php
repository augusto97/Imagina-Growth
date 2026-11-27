<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Billing\Http\EnsurePlatformAdmin;
use App\Domain\Platform\Http\PlatformAdminsController;
use App\Domain\Platform\Http\PlatformHolidaysController;
use App\Domain\Platform\Http\PlatformSettingsController;
use App\Domain\Platform\Http\PlatformWaRatesController;
use App\Domain\Platform\Http\RequirePlatformTwoFactor;
use App\Domain\Platform\Settings\PlatformSettings;
use App\Domain\Platform\Settings\PlatformSettingsRuntime;
use App\Support\Queue\QueueActivity;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Ajustes de la plataforma desde el panel (F17): lo que antes se configuraba en el .env o con comandos se guarda en
 * `platform_settings` y se aplica sobre config() al arrancar cada proceso (antes que cualquier otro proveedor) y en
 * caliente en los procesos largos: antes de cada trabajo de las colas, en cada petición (RefreshPlatformSettings) y en
 * los workers de ingesta. Rutas en /api/platform (solo superadmin, siempre con 2FA).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);
        $this->app->singleton(PlatformSettingsRuntime::class);
        // Antes de que arranque cualquier proveedor: las tareas programadas y los listeners ya leen los ajustes del panel.
        $this->app->booting(fn () => $this->app->make(PlatformSettingsRuntime::class)->boot());
    }

    public function boot(): void
    {
        Event::listen(JobProcessing::class, fn () => $this->app->make(PlatformSettingsRuntime::class)->refreshIfStale());
        // Señal de operación (SetupStatus, «no llegan avisos»): cuándo terminó el último trabajo de cola.
        Event::listen(JobProcessed::class, fn () => QueueActivity::record());

        if (! $this->app->routesAreCached()) {
            Route::prefix('api/platform')->middleware(['api', 'auth:sanctum', EnsurePlatformAdmin::class, RequirePlatformTwoFactor::class])
                ->group(function (): void {
                    Route::get('settings', [PlatformSettingsController::class, 'show']);
                    Route::post('settings/push/vapid', [PlatformSettingsController::class, 'vapid'])->middleware('throttle:6,1');
                    Route::post('settings/ses/setup', [PlatformSettingsController::class, 'sesSetup'])->middleware('throttle:6,1');
                    Route::post('settings/ai/test', [PlatformSettingsController::class, 'aiTest'])->middleware('throttle:6,1');
                    Route::put('settings/{section}', [PlatformSettingsController::class, 'update'])->middleware('throttle:60,1');
                    Route::get('holidays', [PlatformHolidaysController::class, 'index']);
                    Route::put('holidays/{country}/{date}', [PlatformHolidaysController::class, 'update']);
                    Route::delete('holidays/{country}/{date}', [PlatformHolidaysController::class, 'destroy']);
                    Route::get('wa-rates', [PlatformWaRatesController::class, 'index']);
                    Route::put('wa-rates', [PlatformWaRatesController::class, 'update']);
                    Route::post('wa-rates/seed', [PlatformWaRatesController::class, 'seed'])->middleware('throttle:6,1');
                    Route::get('admins', [PlatformAdminsController::class, 'index']);
                    Route::post('admins', [PlatformAdminsController::class, 'store'])->middleware('throttle:10,1');
                    Route::delete('admins/{user}', [PlatformAdminsController::class, 'destroy'])->middleware('throttle:10,1');
                });
        }
    }
}
