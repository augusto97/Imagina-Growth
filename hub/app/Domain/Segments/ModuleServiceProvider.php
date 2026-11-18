<?php

declare(strict_types=1);

namespace App\Domain\Segments;

use App\Domain\Segments\Console\InstallLibraryCommand;
use App\Domain\Segments\Console\PerfDatasetCommand;
use App\Domain\Segments\Console\RecomputeSegmentsCommand;
use App\Domain\Segments\Console\SegmentBenchmarkCommand;
use App\Domain\Segments\Library\SegmentLibrary;
use App\Domain\Segments\Listeners\QueueSegmentEvaluation;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Segmentación (modulos.md M04): listeners, comandos y recálculo nocturno. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tiempo real: cada evento de un contacto puede moverlo de segmento.
        Event::listen(EventIngested::class, QueueSegmentEvaluation::class);

        // Cada espacio de trabajo nace con la biblioteca de segmentos del sistema.
        Workspace::created(function (Workspace $workspace): void {
            app(SegmentLibrary::class)->install($workspace);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RecomputeSegmentsCommand::class, InstallLibraryCommand::class, PerfDatasetCommand::class, SegmentBenchmarkCommand::class]);
        }

        // Recálculo completo cada noche (escalonado por espacio de trabajo dentro del comando).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('igr:segments:recompute --all')->dailyAt('02:40')->onOneServer()->withoutOverlapping();
        });
    }
}
