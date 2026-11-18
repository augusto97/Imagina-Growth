<?php

declare(strict_types=1);

namespace App\Domain\Agency;

use App\Domain\Agency\Console\MonthlyReportsCommand;
use App\Domain\Agency\Domains\DnsResolver;
use App\Domain\Agency\Domains\SystemDnsResolver;
use App\Domain\Agency\Support\AgencyBranding;
use App\Domain\Workspaces\Contracts\Branding;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * M22 Capa de agencia: panel de clientes, plantillas maestras, reporte mensual
 * con marca y marca blanca (marca de la cuenta y dominios propios).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // La marca sale de account_branding de la cuenta del espacio de trabajo (reemplaza DefaultBranding).
        $this->app->scoped(Branding::class, AgencyBranding::class);
        $this->app->bindIf(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([MonthlyReportsCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:agency:monthly-reports --due')->hourlyAt(17)->withoutOverlapping()->onOneServer();
        });
    }
}
