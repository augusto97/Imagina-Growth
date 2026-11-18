<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Jobs;

use App\Domain\Recommendations\Stats\AssociationRulesBuilder;
use App\Domain\Recommendations\Stats\ProductStatsBuilder;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Datos de las recomendaciones de un espacio de trabajo: popularidad y
 * tendencia de cada producto y, con `associations`, las reglas de «comprados
 * juntos» (la parte pesada, una vez por noche).
 */
final class BuildRecommendationData implements ShouldBeUnique, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1200;

    public int $uniqueFor = 1800;

    public function __construct(public string $workspaceIdValue, public bool $associations = true)
    {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return $this->workspaceIdValue.':'.($this->associations ? 'all' : 'stats');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(CurrentWorkspace $current, ProductStatsBuilder $stats, AssociationRulesBuilder $rules): void
    {
        $workspace = $current->get();
        foreach (Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->get() as $site) {
            $stats->build($workspace, $site);
            if ($this->associations) {
                $rules->build($workspace, $site);
            }
        }
    }
}
