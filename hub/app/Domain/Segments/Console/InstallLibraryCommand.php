<?php

declare(strict_types=1);

namespace App\Domain\Segments\Console;

use App\Domain\Segments\Library\SegmentLibrary;
use App\Support\Console\WorkspaceOption;
use Illuminate\Console\Command;

final class InstallLibraryCommand extends Command
{
    protected $signature = 'igr:segments:install-library {--workspace=* : ws_… (por defecto todos)}';

    protected $description = 'Crea los segmentos del sistema que falten (biblioteca de M04) en los espacios de trabajo existentes';

    public function handle(SegmentLibrary $library): int
    {
        $workspaces = WorkspaceOption::resolve((array) $this->option('workspace'));
        $created = 0;
        foreach ($workspaces as $workspace) {
            $created += $library->install($workspace);
        }
        $this->info("Segmentos del sistema creados: {$created}");

        return self::SUCCESS;
    }
}
