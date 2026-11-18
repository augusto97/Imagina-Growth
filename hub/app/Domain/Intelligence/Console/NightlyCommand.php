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

final class NightlyCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:nightly {--spread=60 : Minutos para escalonar los espacios de trabajo}';

    protected $description = 'Encola KPI, RFM y hallazgos de cada espacio de trabajo, escalonados';

    public function handle(): int
    {
        $spread = max(1, (int) $this->option('spread'));
        foreach ($this->workspaces() as $i => $ws) {
            RunNightlyIntelligence::dispatch($ws->id)->delay(now()->addMinutes($i % $spread));
        }

        return self::SUCCESS;
    }
}
