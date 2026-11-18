<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Console;

use App\Analytics\Ch;
use App\Domain\Reviews\Actions\ProjectStoreReview;
use App\Domain\Sites\Models\Site;
use App\Support\Console\WorkspaceOption;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * Proyecta en `reviews` las reseñas nativas que llegaron con la importación
 * histórica (review.submitted con source.type = import no publica
 * EventIngested). Lee los eventos de ClickHouse; es idempotente.
 */
final class ProjectStoreReviewsCommand extends Command
{
    protected $signature = 'igr:reviews:project-store {--workspace=* : ws_… (por defecto todos)}';

    protected $description = 'Proyecta las reseñas de WooCommerce importadas (review.submitted) en la tabla de reseñas';

    public function handle(ProjectStoreReview $projector, CurrentWorkspace $current): int
    {
        foreach (WorkspaceOption::resolve((array) $this->option('workspace')) as $workspace) {
            $count = $current->run($workspace, function () use ($workspace, $projector): int {
                $sites = Site::query()->get()->keyBy('ch_id');
                $rows = Ch::forWorkspace($workspace)->select(
                    "SELECT site_id, contact_id, toString(occurred_at) AS at, event_id, toJSONString(properties) AS p FROM events
                     WHERE workspace_id = {workspace_id:UInt32} AND event = 'review.submitted' ORDER BY occurred_at, event_id",
                );
                $done = 0;
                foreach ($rows as $row) {
                    $site = $sites->get((int) $row['site_id']);
                    $props = json_decode((string) $row['p'], true);
                    if ($site === null || ! is_array($props)) {
                        continue;
                    }
                    $envelope = ['occurred_at' => (string) $row['at'].'Z', 'properties' => $props];
                    $projector->handle($site, $envelope, ($row['contact_id'] ?? '') !== '' ? (string) $row['contact_id'] : null);
                    $done++;
                }

                return $done;
            });
            $this->line(sprintf('%s: %d reseñas de la tienda proyectadas.', $workspace->name, $count));
        }

        return self::SUCCESS;
    }
}
