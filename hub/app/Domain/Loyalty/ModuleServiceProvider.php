<?php

declare(strict_types=1);

namespace App\Domain\Loyalty;

use App\Domain\Loyalty\Console\LoyaltyNightlyCommand;
use App\Domain\Loyalty\Console\LoyaltySettleCommand;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Listeners\LoyaltyEventListener;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Fidelización (M13): puntos, niveles VIP y saldo a favor. El libro
 * (DatabaseLoyaltyLedger) es uno por petición o job: recuerda el programa del
 * espacio de trabajo activo.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(DatabaseLoyaltyLedger::class);
        $this->app->bind(LoyaltyLedger::class, DatabaseLoyaltyLedger::class);
    }

    public function boot(): void
    {
        Event::listen(EventIngested::class, LoyaltyEventListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([LoyaltySettleCommand::class, LoyaltyNightlyCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:loyalty:settle')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('igr:loyalty:nightly')->dailyAt('03:41')->withoutOverlapping()->onOneServer();
        });
    }
}
