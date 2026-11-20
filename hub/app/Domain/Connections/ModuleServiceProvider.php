<?php

declare(strict_types=1);

namespace App\Domain\Connections;

use App\Domain\Connections\Console\CheckConnectionsCommand;
use App\Domain\Connections\Providers\ProviderRegistry;
use App\Domain\Connections\Support\ConnectionTokens;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Conexiones con un clic (docs/auditoria-2026-11.md §6): OAuth de Meta, Google y TikTok por espacio de trabajo,
 * Embedded Signup de WhatsApp y el puente de credenciales para Medición, audiencias, canales y la agenda.
 * Rutas: routes/api/connections.php y GET /oauth/{proveedor}/callback en routes/web.php.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ConnectionTokens::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckConnectionsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:connections:check')->dailyAt('03:52')->withoutOverlapping()->onOneServer();
        });
    }
}
