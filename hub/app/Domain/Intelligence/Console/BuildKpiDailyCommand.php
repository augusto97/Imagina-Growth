<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Analytics\Builders\KpiDailyBuilder;
use Illuminate\Console\Command;

final class BuildKpiDailyCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:kpi:build {--workspace=} {--days=3}';

    protected $description = 'Recalcula kpi_daily de los últimos días';

    public function handle(KpiDailyBuilder $builder): int
    {
        foreach ($this->workspaces() as $ws) {
            $builder->build($ws, (int) $this->option('days'));
        }

        return self::SUCCESS;
    }
}
