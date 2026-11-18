<?php

declare(strict_types=1);

namespace App\Domain\Segments\Console;

use App\Domain\Segments\Library\SegmentLibrary;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Console\Command;

final class InstallLibraryCommand extends Command
{
    protected $signature = 'igr:segments:install-library {--workspace=* : ws_… (por defecto todos)}';

    protected $description = 'Crea los segmentos del sistema que falten (biblioteca de M04) en los espacios de trabajo existentes';

    public function handle(SegmentLibrary $library): int
    {
        $ids = array_filter(array_map(fn ($id) => PrefixedId::decode('ws', (string) $id), (array) $this->option('workspace')));
        $workspaces = $ids === [] ? Workspace::query()->get() : Workspace::query()->whereIn('id', $ids)->get();
        $created = 0;
        foreach ($workspaces as $workspace) {
            $created += $library->install($workspace);
        }
        $this->info("Segmentos del sistema creados: {$created}");

        return self::SUCCESS;
    }
}
