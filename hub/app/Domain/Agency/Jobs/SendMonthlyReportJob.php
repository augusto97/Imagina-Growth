<?php

declare(strict_types=1);

namespace App\Domain\Agency\Jobs;

use App\Domain\Agency\Reports\MonthlyReports;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Genera el reporte mensual de un espacio de trabajo y lo envía (una vez por mes salvo `force`). */
final class SendMonthlyReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public string $workspaceId, public string $month, public bool $force = false) {}

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->month;
    }

    public function handle(MonthlyReports $reports): void
    {
        $ws = Workspace::query()->find($this->workspaceId);
        if ($ws === null) {
            return;
        }
        $period = CarbonImmutable::createFromFormat('!Y-m-d', $this->month.'-01', (string) ($ws->timezone ?: 'America/Bogota'));
        if ($period === null) {
            return;
        }
        $report = $reports->generate($ws, $period, $this->force);
        $reports->send($ws, $report, $this->force);
    }
}
