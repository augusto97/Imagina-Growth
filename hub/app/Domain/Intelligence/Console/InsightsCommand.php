<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Intelligence\Insights\InsightEngine;
use Illuminate\Console\Command;

final class InsightsCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:insights {--workspace=} {--all : Corre también los detectores semanales aunque no sea lunes} {--detector=* : Solo estos detectores (clave)}';

    protected $description = 'Corre los detectores de hallazgos';

    public function handle(InsightEngine $engine): int
    {
        foreach ($this->workspaces() as $ws) {
            $only = (array) $this->option('detector');
            $summary = $engine->run($ws, null, (bool) $this->option('all'), $only === [] ? null : array_values(array_map('strval', $only)));
            $this->line($ws->name.': '.json_encode($summary));
        }

        return self::SUCCESS;
    }
}
