<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Console;

use App\Domain\Recommendations\Jobs\NotifyProductWatchesJob;
use App\Support\Console\WorkspaceOption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Barrido de «avísame» (cada 10 minutos): retoma los aplazados por la política
 * de envío y los productos que cambiaron sin evento. Encola un job por sitio.
 */
final class NotifyWatchesCommand extends Command
{
    protected $signature = 'igr:watches:notify {--workspace=* : ID del espacio de trabajo (ws_…)} {--sync : Ejecutar ahora en lugar de encolar}';

    protected $description = 'Envía los avisos de stock y precio que ya se cumplen';

    public function handle(): int
    {
        try {
            $workspaces = WorkspaceOption::resolve($this->option('workspace'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $sites = DB::table('product_watches')->where('status', 'active')->whereIn('workspace_id', $workspaces->modelKeys())
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->distinct()->get(['workspace_id', 'site_id']);
        foreach ($sites as $row) {
            $job = new NotifyProductWatchesJob((string) $row->workspace_id, (string) $row->site_id);
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }
        $this->info(__(':n sitios con avisos por revisar.', ['n' => $sites->count()]));

        return self::SUCCESS;
    }
}
