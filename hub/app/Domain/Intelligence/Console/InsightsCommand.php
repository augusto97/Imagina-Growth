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

final class InsightsCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:insights {--workspace=}';

    protected $description = 'Corre los detectores de hallazgos';

    public function handle(InsightEngine $engine): int
    {
        foreach ($this->workspaces() as $ws) {
            $summary = $engine->run($ws);
            $this->line($ws->name.': '.json_encode($summary));
        }

        return self::SUCCESS;
    }
}
