<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Intelligence\Rfm\RfmCalculator;
use Illuminate\Console\Command;

final class RfmCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:rfm {--workspace=}';

    protected $description = 'Calcula el RFM adaptado a LATAM (inteligencia.md §3)';

    public function handle(RfmCalculator $rfm): int
    {
        foreach ($this->workspaces() as $ws) {
            $m = $rfm->run($ws);
            $this->line(sprintf('%s: %d clientes (%s)', $ws->name, $m['population'], $m['method']));
        }

        return self::SUCCESS;
    }
}
