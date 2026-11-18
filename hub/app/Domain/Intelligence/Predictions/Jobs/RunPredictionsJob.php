<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Jobs;

use App\Domain\Intelligence\Predictions\Actions\RunPredictions;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Predicciones de un espacio de trabajo en la cola `analytics` (conexión `redis-long`, trabajo largo). */
final class RunPredictionsJob implements ShouldBeUnique, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param list<string> $only */
    public function __construct(public string $workspaceIdValue, public bool $train = false, public array $only = [])
    {
        $this->onConnection('redis-long');
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return 'predictions:'.$this->workspaceIdValue;
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

    public function handle(RunPredictions $run): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        if ($workspace !== null) {
            $run->handle($workspace, $this->train, $this->only);
        }
    }
}
