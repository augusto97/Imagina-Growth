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

final class BuildSessionsCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:sessions:build {--workspace=} {--minutes=180 : Ventana hacia atrás} {--full : Reconstruye 25 meses}';

    protected $description = 'Arma las sesiones cerradas desde los eventos (cada 10 minutos)';

    public function handle(SessionsBuilder $builder): int
    {
        $since = $this->option('full') ? CarbonImmutable::now()->subMonths(25) : CarbonImmutable::now()->subMinutes((int) $this->option('minutes'));
        foreach ($this->workspaces() as $ws) {
            $builder->build($ws, $since);
        }

        return self::SUCCESS;
    }
}
