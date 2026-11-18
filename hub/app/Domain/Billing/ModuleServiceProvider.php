<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Console\BillingStatusCommand;
use App\Domain\Billing\Console\BillingUsageCommand;
use App\Domain\Billing\Console\PlansSyncCommand;
use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Http\EnsurePlatformAdmin;
use App\Domain\Billing\Invoices\FakePaymentLinkProvider;
use App\Domain\Billing\Invoices\ManualPaymentLinkProvider;
use App\Domain\Billing\Invoices\PaymentLinkProvider;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanEntitlements;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * M21 Planes y facturación: `Entitlements` con planes y Pennant, uso del mes,
 * avisos, periodo de gracia, facturas y el panel interno de la plataforma
 * (/api/platform, solo superadmin).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El plan se guarda en memoria por petición o trabajo, nunca entre trabajos.
        $this->app->scoped(PlanResolver::class);
        $this->app->bind(Entitlements::class, PlanEntitlements::class);
        $this->app->bind(PaymentLinkProvider::class, fn () => match (config('igr-plans.invoices.payment_link_provider')) {
            'fake' => new FakePaymentLinkProvider,
            default => new ManualPaymentLinkProvider,
        });
    }

    public function boot(): void
    {
        foreach (PlanCatalog::modules() as $module) {
            Feature::define(PlanResolver::feature($module), fn (mixed $scope): bool => $scope instanceof Workspace
                && app(PlanResolver::class)->moduleAllowed($scope, $module));
        }

        if (! $this->app->routesAreCached()) {
            Route::prefix('api/platform')->middleware(['api', 'auth:sanctum', EnsurePlatformAdmin::class])
                ->group(base_path('routes/platform.php'));
        }

        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->commands([PlansSyncCommand::class, BillingUsageCommand::class, BillingStatusCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:billing:usage')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('igr:billing:status')->hourlyAt(23)->withoutOverlapping()->onOneServer();
        });
    }
}
