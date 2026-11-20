<?php

declare(strict_types=1);

namespace App\Domain\Sites\Console;

use App\Domain\Sites\Health\SiteHealth;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * F6 (docs/auditoria-2026-11.md): marca `degraded` el sitio que no está al día (sin eventos del servidor, WP-Cron
 * atrasado, sin visitas del navegador, rechazos, importación detenida…) y lo devuelve a `connected` cuando se
 * recupera. Corre cada 10 minutos.
 */
final class SiteHealthCommand extends Command
{
    protected $signature = 'igr:sites:health';

    protected $description = 'Revisa la salud de los sitios conectados y marca los que tienen problemas';

    public function handle(SiteHealth $health, CurrentWorkspace $current): int
    {
        $changed = 0;
        $workspaceIds = Site::withoutWorkspaceScope()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])
            ->distinct()->pluck('workspace_id')->all();
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
            try {
                $changed += $current->run($workspace, function () use ($workspace, $health): int {
                    $sites = Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->get();
                    $n = 0;
                    foreach ($health->forSites($workspace, $sites) as $siteId => $result) {
                        $site = $sites->firstWhere('id', $siteId);
                        $target = $result['state'] === SiteHealth::OK ? Site::STATUS_CONNECTED : Site::STATUS_DEGRADED;
                        if ($site !== null && $site->status !== $target) {
                            // Solo el estado: last_seen_at y health son del heartbeat.
                            Site::query()->whereKey($site->id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->update(['status' => $target]);
                            $n++;
                        }
                    }

                    return $n;
                });
            } catch (Throwable $e) {
                report($e);
                Log::warning('igr.sites.health_failed', ['workspace_id' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
        $this->info("Sitios con cambio de estado: {$changed}");

        return self::SUCCESS;
    }
}
