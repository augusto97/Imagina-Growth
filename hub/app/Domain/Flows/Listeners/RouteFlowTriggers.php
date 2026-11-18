<?php

declare(strict_types=1);

namespace App\Domain\Flows\Listeners;

use App\Domain\Flows\Graph\Filters;
use App\Domain\Flows\Jobs\EnterFlowJob;
use App\Domain\Flows\Support\EventProps;
use App\Domain\Flows\Triggers\FlowSignals;
use App\Domain\Flows\Triggers\TriggerIndex;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TriggerRouter (hub/CLAUDE.md §7): por cada evento de un contacto mira el
 * índice de Redis; si algún flujo entra con ese evento (y pasan sus filtros)
 * encola la entrada, y si importa a runs vivos (meta o espera por evento) los
 * atiende. Nunca rompe la ingesta: los errores se reportan y se sigue.
 */
final class RouteFlowTriggers
{
    public function __construct(
        private readonly TriggerIndex $index,
        private readonly FlowSignals $signals,
        private readonly CurrentWorkspace $current,
    ) {}

    public function handle(EventIngested $event): void
    {
        // Los eventos de los propios flujos no disparan flujos (evita ciclos).
        if (str_starts_with($event->event, 'flow.') || $event->contactId === null || $event->contactId === '') {
            return;
        }
        try {
            $lookup = $this->index->lookup($event->workspaceId, $event->event);
            if ($lookup['triggers'] === [] && ! $lookup['wake']) {
                return;
            }
            $props = (array) ($event->envelope['properties'] ?? []);
            $clean = null;
            foreach ($lookup['triggers'] as $trigger) {
                if (Filters::matches((array) $trigger['filters'], $props)) {
                    $clean ??= EventProps::sanitize($props);
                    EnterFlowJob::dispatch($event->workspaceId, $trigger['flow'], $event->contactId, $event->event, $event->eventId, $clean);
                }
            }
            if ($lookup['wake']) {
                $workspace = Workspace::query()->find($event->workspaceId);
                if ($workspace !== null) {
                    $contactId = $event->contactId;
                    $this->current->run($workspace, fn () => $this->signals->onEvent($contactId, $event->event, $event->eventId, $props));
                }
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.flows.router_failed', ['event' => $event->event, 'workspace' => $event->workspaceId]);
        }
    }
}
