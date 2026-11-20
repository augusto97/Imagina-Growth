<?php

declare(strict_types=1);

namespace App\Domain\Segments\Listeners;

use App\Domain\Segments\Jobs\EvaluateContactSegmentsJob;
use App\Domain\Segments\Support\DependencyIndex;
use App\Domain\Segments\Support\PendingSegmentEvaluation;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Al llegar un evento de un contacto, anota los segmentos que dependen de ese
 * tipo de evento y encola el recálculo del contacto (único por contacto, con
 * espera): solo esos segmentos se reevalúan (R8 de la auditoría). Con el índice
 * en caché no se consulta la base.
 */
final class QueueSegmentEvaluation
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(EventIngested $event): void
    {
        if ($event->contactId === null || $event->contactId === '') {
            return;
        }
        $index = DependencyIndex::cached($event->workspaceId);
        if ($index === null) {
            $workspace = Workspace::query()->find($event->workspaceId);
            if ($workspace === null) {
                return;
            }
            $index = $this->current->run($workspace, fn () => DependencyIndex::load($workspace->id));
        }
        $segmentIds = DependencyIndex::relevantSegments($index, $event->event);
        if ($segmentIds === []) {
            return;
        }
        PendingSegmentEvaluation::add($event->workspaceId, $event->contactId, $segmentIds);
        EvaluateContactSegmentsJob::dispatch($event->workspaceId, $event->contactId, true)->delay(now()->addSeconds(EvaluateContactSegmentsJob::DELAY_SECONDS));
    }
}
