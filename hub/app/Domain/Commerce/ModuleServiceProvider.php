<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Actions\SiteCouponIssuer;
use App\Domain\Commerce\Console\AbandonCartsCommand;
use App\Domain\Commerce\Contracts\CouponIssuer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Comercio: carritos y cupones (hub/CLAUDE.md §5). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CouponIssuer::class, SiteCouponIssuer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AbandonCartsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:carts:abandon')->everyMinute()->withoutOverlapping()->onOneServer();
        });
    }
}
