<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Console;

use App\Domain\Commerce\Models\Order;
use App\Domain\Measurement\Attribution\OrderAttributor;
use App\Domain\Measurement\Attribution\TouchQuery;
use App\Support\Console\WorkspaceOption;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atribuye los pedidos sin canal (sobre todo los importados, que no pasan por
 * el evento en vivo). Corre cada noche y se puede lanzar a mano.
 */
final class AttributionBackfillCommand extends Command
{
    protected $signature = 'igr:attribution:backfill {--workspace=* : ws_… (por defecto todos)} {--force : Recalcula también los ya atribuidos}';

    protected $description = 'Calcula channel_first y channel_last de los pedidos (tracker o atribución de WooCommerce)';

    public function handle(OrderAttributor $attributor, TouchQuery $touches, CurrentWorkspace $current): int
    {
        $workspaces = WorkspaceOption::resolve((array) $this->option('workspace'));

        foreach ($workspaces as $workspace) {
            $count = $current->run($workspace, function () use ($workspace, $attributor, $touches): int {
                try {
                    $useTracker = $touches->hasBrowserData($workspace);
                } catch (Throwable) {
                    $useTracker = false;
                }
                $done = 0;
                Order::query()
                    ->when(! $this->option('force'), fn ($q) => $q->whereNull('channel_last'))
                    ->orderBy('id')
                    ->chunkById(500, function ($orders) use ($workspace, $attributor, $useTracker, &$done): void {
                        foreach ($orders as $order) {
                            $attributor->attribute($workspace, $order, [], $useTracker);
                            $done++;
                        }
                    });

                return $done;
            });
            $this->line(sprintf('%s: %d pedidos atribuidos.', $workspace->name, $count));
        }

        return self::SUCCESS;
    }
}
