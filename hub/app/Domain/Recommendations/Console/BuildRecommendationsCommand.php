<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Console;

use App\Domain\Recommendations\Jobs\BuildRecommendationData;
use App\Support\Console\WorkspaceOption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `igr:reco:build`: popularidad y tendencia (cada 3 horas, `--stats-only`) y
 * reglas de asociación (cada noche). Un job por espacio de trabajo con sitios conectados.
 */
final class BuildRecommendationsCommand extends Command
{
    protected $signature = 'igr:reco:build {--workspace=* : ID del espacio de trabajo (ws_…)} {--stats-only : Solo popularidad y tendencia} {--sync : Ejecutar ahora en lugar de encolar}';

    protected $description = 'Calcula la popularidad de los productos y las reglas de «comprados juntos»';

    public function handle(): int
    {
        try {
            $workspaces = WorkspaceOption::resolve($this->option('workspace'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $withSites = DB::table('sites')->whereIn('status', ['connected', 'degraded'])->whereIn('workspace_id', $workspaces->modelKeys())->distinct()->pluck('workspace_id')->all();
        foreach ($withSites as $id) {
            $job = new BuildRecommendationData((string) $id, ! $this->option('stats-only'));
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }
        $this->info(__(':n espacios de trabajo.', ['n' => count($withSites)]));

        return self::SUCCESS;
    }
}
