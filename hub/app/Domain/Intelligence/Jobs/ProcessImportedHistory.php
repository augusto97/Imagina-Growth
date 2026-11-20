<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Jobs;

use App\Analytics\Builders\KpiDailyBuilder;
use App\Domain\Commerce\Models\Order;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Measurement\Attribution\OrderAttributor;
use App\Domain\Measurement\Attribution\TouchQuery;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\IngestStream;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Al terminar la importación histórica (docs/auditoria-2026-11.md F8): atribución de los pedidos, KPI diarios,
 * RFM y hallazgos. Antes había que esperar al trabajo nocturno y el panel quedaba en cero el
 * primer día. Espera a que el stream de ingesta de ese espacio quede al día (hasta ~10 minutos).
 */
final class ProcessImportedHistory implements ShouldBeUnique, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 12;

    public int $uniqueFor = 3600;

    public function __construct(public string $workspaceIdValue)
    {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return $this->workspaceIdValue;
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

    public function handle(IngestStream $stream, OrderAttributor $attributor, TouchQuery $touches, KpiDailyBuilder $kpi, RfmCalculator $rfm, InsightEngine $insights): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceIdValue);
        if ($this->attempts() < $this->tries && $this->backlog($stream, $workspace) > 0) {
            $this->release(60);

            return;
        }

        try {
            $useTracker = $touches->hasBrowserData($workspace);
        } catch (Throwable) {
            $useTracker = false;
        }
        Order::query()->whereNull('channel_last')->orderBy('id')->chunkById(500, function ($orders) use ($workspace, $attributor, $useTracker): void {
            foreach ($orders as $order) {
                $attributor->attribute($workspace, $order, [], $useTracker);
            }
        });

        // Los KPI diarios salen de las sesiones del tracker (las ventas, de `orders`): la importación no trae visitas,
        // así que basta con el último mes, que cubre todo lo que el tracker pudo medir desde que se conectó el sitio.
        $kpi->build($workspace, 35);
        $rfm->run($workspace);
        $insights->run($workspace);
    }

    /** Eventos sin procesar en el shard de este espacio de trabajo. */
    private function backlog(IngestStream $stream, Workspace $workspace): int
    {
        try {
            /** @var \Redis $redis */
            $redis = $stream->connection()->client();
            $key = $stream->key($stream->shardFor($workspace->ch_id));
            $group = (string) config('igr.ingest.consumer_group');
            $pending = $redis->xPending($key, $group);
            $lag = 0;
            foreach ((array) $redis->xInfo('GROUPS', $key) as $g) {
                if (is_array($g) && ($g['name'] ?? null) === $group) {
                    $lag = (int) ($g['lag'] ?? 0);
                }
            }

            return $lag + (is_array($pending) ? (int) ($pending[0] ?? 0) : 0);
        } catch (Throwable) {
            return 0;
        }
    }
}
