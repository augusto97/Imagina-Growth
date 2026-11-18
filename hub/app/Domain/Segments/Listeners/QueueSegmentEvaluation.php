<?php

declare(strict_types=1);

namespace App\Domain\Segments\Listeners;

use App\Domain\Segments\Jobs\EvaluateContactSegmentsJob;
use App\Domain\Segments\Support\DependencyIndex;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Al llegar un evento de un contacto, encola su recálculo de segmentos si
 * algún segmento depende de ese tipo de evento.
 */
final class QueueSegmentEvaluation
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(EventIngested $event): void
    {
        if ($event->contactId === null || $event->contactId === '') {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }
        $relevant = $this->current->run($workspace, fn () => DependencyIndex::isRelevant(DependencyIndex::load($workspace->id), $event->event));
        if ($relevant) {
            EvaluateContactSegmentsJob::dispatch($workspace->id, $event->contactId)->delay(now()->addSeconds(EvaluateContactSegmentsJob::DELAY_SECONDS));
        }
    }
}
