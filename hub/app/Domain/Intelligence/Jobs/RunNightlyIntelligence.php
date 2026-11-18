<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Jobs;

use App\Analytics\Builders\KpiDailyBuilder;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Trabajo nocturno de un espacio de trabajo (hub/CLAUDE.md §15): KPI diarios
 * de los últimos 3 días, RFM y hallazgos. Se encola escalonado por espacio de
 * trabajo en la cola `analytics`.
 */
final class RunNightlyIntelligence implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public function __construct(public string $workspaceIdValue)
    {
        $this->onQueue('analytics');
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

    public function handle(KpiDailyBuilder $kpi, RfmCalculator $rfm, InsightEngine $insights): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceIdValue);
        $kpi->build($workspace, 3);
        $rfm->run($workspace);
        $insights->run($workspace);
    }
}
