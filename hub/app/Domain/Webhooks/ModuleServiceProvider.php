<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Domain\Webhooks\Console\PruneWebhookDeliveriesCommand;
use App\Domain\Webhooks\Console\RetryWebhooksCommand;
use App\Domain\Webhooks\Listeners\FanOutWebhooks;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Webhooks salientes (modulos.md M19): de cada evento de la ingesta a las URL suscritas,
 * firmados, con reintentos, pausa automática y registro de entregas.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventIngested::class, FanOutWebhooks::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RetryWebhooksCommand::class, PruneWebhookDeliveriesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:webhooks:retry')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('igr:webhooks:prune')->dailyAt('04:20')->onOneServer();
        });
    }
}
