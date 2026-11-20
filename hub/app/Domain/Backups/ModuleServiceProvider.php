<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Http\BackupController;
use App\Domain\Billing\Http\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Copias de seguridad desde el panel de la plataforma (docs/despliegue.md §6): destino en el Storage Box, prueba de
 * conexión, copia ahora y el estado de cada copia. El panel solo deja pedidos; los atiende growth-backup-dispatch
 * (root). Solo superadmin con 2FA.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }
        Route::prefix('api/platform/backups')->middleware(['api', 'auth:sanctum', EnsurePlatformAdmin::class])
            ->group(function (): void {
                Route::get('/', [BackupController::class, 'show']);
                Route::put('settings', [BackupController::class, 'saveSettings'])->middleware('throttle:10,1');
                Route::post('test', [BackupController::class, 'test'])->middleware('throttle:10,1');
                Route::post('run', [BackupController::class, 'run'])->middleware('throttle:6,1');
                Route::post('refresh', [BackupController::class, 'refresh'])->middleware('throttle:20,1');
                Route::post('install-key', [BackupController::class, 'installKey'])->middleware('throttle:6,1');
            });
    }
}
