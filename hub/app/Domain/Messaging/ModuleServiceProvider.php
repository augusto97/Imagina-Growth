<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use App\Domain\Messaging\Console\RequeueDeferredCommand;
use App\Domain\Messaging\Console\ResolveStuckMessagesCommand;
use App\Domain\Messaging\Console\SesSetupCommand;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Sending\DefaultMessageDispatcher;
use App\Domain\Messaging\Support\DnsResolver;
use App\Domain\Messaging\Support\SesClientFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Mensajería (M07, hub/CLAUDE.md §8): despachador, drivers, webhooks y enlaces. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MessageDispatcher::class, DefaultMessageDispatcher::class);
        $this->app->singleton(SesClientFactory::class);
        $this->app->singleton(DnsResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RequeueDeferredCommand::class, ResolveStuckMessagesCommand::class, SesSetupCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:messages:requeue')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('igr:messages:stuck')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
