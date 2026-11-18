<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Intelligence\Jobs\RunNightlyIntelligence;
use Illuminate\Console\Command;

final class NightlyCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:nightly {--workspace= : ws_… de un solo espacio de trabajo} {--spread=60 : Minutos para escalonar los espacios de trabajo}';

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
