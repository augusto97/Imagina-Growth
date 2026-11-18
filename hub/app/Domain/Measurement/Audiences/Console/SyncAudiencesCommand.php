<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Console;

use App\Domain\Measurement\Audiences\Jobs\SyncAudienceJob;
use App\Support\Console\WorkspaceOption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** `igr:audiences:sync` (cada 12 h): una sincronización por audiencia activa, pendiente o con error. */
final class SyncAudiencesCommand extends Command
{
    protected $signature = 'igr:audiences:sync {--workspace=* : ID del espacio de trabajo (ws_…)} {--sync : Ejecutar ahora en lugar de encolar}';

    protected $description = 'Sincroniza los segmentos con las audiencias de Meta, Google y TikTok';

    public function handle(): int
    {
        try {
            $workspaces = WorkspaceOption::resolve($this->option('workspace'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $rows = DB::table('audience_syncs')->whereIn('workspace_id', $workspaces->modelKeys())->whereIn('status', ['pending', 'active', 'error'])->get(['id', 'workspace_id']);
        foreach ($rows as $row) {
            $job = new SyncAudienceJob((string) $row->workspace_id, (string) $row->id);
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }
        $this->info(__(':n audiencias.', ['n' => $rows->count()]));

        return self::SUCCESS;
    }
}
