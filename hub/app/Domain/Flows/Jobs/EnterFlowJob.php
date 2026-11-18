<?php

declare(strict_types=1);

namespace App\Domain\Flows\Jobs;

use App\Domain\Flows\Actions\EnterFlow;
use App\Domain\Flows\Models\Flow;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Entrada de un contacto a un flujo por un evento (cola `triggers`). Las
 * propiedades ya vienen sin datos personales (EventProps); igual va cifrado.
 */
final class EnterFlowJob implements ShouldBeEncrypted, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $props */
    public function __construct(
        public string $workspaceIdValue,
        public string $flowId,
        public string $contactId,
        public string $event,
        public string $eventId,
        public array $props,
    ) {
        $this->onQueue('triggers');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(EnterFlow $enter): void
    {
        $flow = Flow::query()->find($this->flowId);
        if ($flow === null) {
            return;
        }
        $run = $enter->handle($flow, $this->contactId, 'event:'.$this->eventId, [
            'trigger' => $this->event,
            'event_id' => $this->eventId,
            'props' => $this->props,
        ]);
        if ($run !== null) {
            AdvanceFlowRun::dispatch($run->workspace_id, $run->id);
        }
    }
}
