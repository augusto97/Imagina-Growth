<?php

declare(strict_types=1);

namespace App\Domain\Reviews;

use App\Domain\Intelligence\Ai\Features\Classifiers\AiReviewModerator;
use App\Domain\Reviews\Console\ProjectStoreReviewsCommand;
use App\Domain\Reviews\Console\SendReviewRequestsCommand;
use App\Domain\Reviews\Contracts\ReviewClassifier;
use App\Domain\Reviews\Listeners\ReviewEventsListener;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Reseñas, NPS y prueba social (modulos.md M15): solicitudes y encuestas por
 * pedido, formulario público, moderación, publicación en WooCommerce y los
 * endpoints públicos de los widgets.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fase 4: moderación con IA (Claude Haiku) sumada a las heurísticas; sin IA, solo las heurísticas.
        $this->app->bind(ReviewClassifier::class, AiReviewModerator::class);
    }

    public function boot(): void
    {
        Event::listen(EventIngested::class, ReviewEventsListener::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendReviewRequestsCommand::class, ProjectStoreReviewsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:reviews:send')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
