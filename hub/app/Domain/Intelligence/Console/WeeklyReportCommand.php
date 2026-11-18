<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Analytics\Builders\KpiDailyBuilder;
use App\Analytics\Builders\SessionsBuilder;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Jobs\RunNightlyIntelligence;
use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class WeeklyReportCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:report:weekly {--workspace=} {--due : Solo a los que les toca (lunes 07:00 local)} {--force : Reenvía aunque ya se haya enviado}';

    protected $description = 'Envía el resumen semanal al dueño';

    public function handle(SendWeeklyReport $send): int
    {
        foreach ($this->workspaces() as $ws) {
            if ($this->option('due') && ! SendWeeklyReport::isDue($ws)) {
                continue;
            }
            $result = $send->handle($ws, null, (bool) $this->option('force'));
            $this->line(sprintf('%s: %s %s', $ws->name, $result['status'], implode(', ', $result['recipients'])));
        }

        return self::SUCCESS;
    }
}
