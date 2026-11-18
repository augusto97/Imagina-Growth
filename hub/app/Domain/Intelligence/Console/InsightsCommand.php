<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Intelligence\Insights\InsightEngine;
use Illuminate\Console\Command;

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
