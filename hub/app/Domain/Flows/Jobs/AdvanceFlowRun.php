<?php

declare(strict_types=1);

namespace App\Domain\Flows\Jobs;

use App\Domain\Flows\Engine\FlowEngine;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Avanza un run (cola `flows`, hub/CLAUDE.md §15). Único por run hasta que empieza a correr. */
final class AdvanceFlowRun implements ShouldBeUniqueUntilProcessing, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public string $workspaceIdValue, public string $runId)
    {
        $this->onQueue('flows');
    }

    public function uniqueId(): string
    {
        return $this->runId;
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

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(FlowEngine $engine): void
    {
        $engine->advance($this->runId);
    }
}
