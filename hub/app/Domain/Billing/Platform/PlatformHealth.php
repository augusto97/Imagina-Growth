<?php

declare(strict_types=1);

namespace App\Domain\Billing\Platform;

use App\Analytics\Ch;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\IngestStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Salud de la plataforma para el panel interno (hub/CLAUDE.md §16, solo superadmin):
 * retraso de ingesta por shard, rechazos de las últimas 24 h, mensajes fallidos por
 * proveedor, entregas de conversiones fallidas y costo de IA del mes por espacio de
 * trabajo. Solo agregados: ningún dato de contacto sale de aquí.
 *
 * Es la única lectura de ClickHouse entre espacios de trabajo, y solo cuenta filas
 * agrupadas por `workspace_id` para el equipo de la plataforma.
 */
final class PlatformHealth
{
    public function __construct(private readonly IngestStream $stream, private readonly PlanResolver $plans) {}

    /** @return array<string, mixed> */
    public function snapshot(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $since = $now->subDay();

        return [
            'ingest' => $this->ingest(),
            'rejections_24h' => DB::table('ingest_rejections')->where('created_at', '>=', $since)
                ->selectRaw('reason, COUNT(*) AS n')->groupBy('reason')->orderByDesc('n')->limit(20)->get()
                ->map(fn ($r) => ['reason' => (string) $r->reason, 'count' => (int) $r->n])->all(),
            'failed_deliveries_24h' => DB::table('messages')->where('direction', 'out')->whereIn('status', ['failed', 'bounced'])
                ->where('updated_at', '>=', $since)
                ->selectRaw('channel, provider, COUNT(*) AS n')->groupBy('channel', 'provider')->orderByDesc('n')->get()
                ->map(fn ($r) => ['channel' => (string) $r->channel, 'provider' => $r->provider !== null ? (string) $r->provider : null, 'count' => (int) $r->n])->all(),
            'conversion_failures_24h' => $this->conversionFailures($since),
            'ai_cost_month' => $this->aiCost($now),
            'time' => $now->toIso8601String(),
        ];
    }

    /** @return list<array{shard: int, length: int, pending: int, lag_seconds: int}>|null */
    private function ingest(): ?array
    {
        try {
            /** @var \Redis $redis */
            $redis = $this->stream->connection()->client();
            $group = (string) config('igr.ingest.consumer_group');
            $out = [];
            for ($shard = 0; $shard < $this->stream->shards(); $shard++) {
                $key = $this->stream->key($shard);
                $pending = $redis->xPending($key, $group);
                $oldest = is_array($pending) && isset($pending[1]) && is_string($pending[1]) ? (int) explode('-', $pending[1])[0] : null;
                $out[] = [
                    'shard' => $shard, 'length' => (int) $redis->xLen($key),
                    'pending' => is_array($pending) ? (int) ($pending[0] ?? 0) : 0,
                    'lag_seconds' => $oldest === null ? 0 : max(0, (int) round(microtime(true) - $oldest / 1000)),
                ];
            }

            return $out;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{platform: string, workspace_id: string|null, workspace: string|null, count: int}>|null */
    private function conversionFailures(CarbonImmutable $since): ?array
    {
        try {
            $rows = Ch::fromConfig()->selectWithParams(
                "SELECT workspace_id, platform, count() AS n FROM conversion_deliveries WHERE delivered_at >= parseDateTime64BestEffort({since:String}, 3) AND status = 'failed' GROUP BY workspace_id, platform ORDER BY n DESC LIMIT 50",
                ['since' => $since->toIso8601String()],
            );
        } catch (Throwable) {
            return null;
        }
        $workspaces = Workspace::query()->whereIn('ch_id', array_map(fn ($r) => (int) $r['workspace_id'], $rows))->get()->keyBy('ch_id');

        return array_map(function (array $r) use ($workspaces): array {
            $ws = $workspaces->get((int) $r['workspace_id']);

            return ['platform' => (string) $r['platform'], 'workspace_id' => $ws?->publicId(), 'workspace' => $ws?->name, 'count' => (int) $r['n']];
        }, $rows);
    }

    /** @return list<array{workspace_id: string, workspace: string, cost_usd: float, budget_usd: int|null, pct: int|null}> */
    private function aiCost(CarbonImmutable $now): array
    {
        $rows = DB::table('ai_usage')->where('date', '>=', $now->startOfMonth()->toDateString())
            ->selectRaw('workspace_id, SUM(cost_usd) AS cost')->groupBy('workspace_id')->orderByDesc('cost')->limit(50)->get();
        $workspaces = Workspace::query()->whereIn('id', $rows->pluck('workspace_id'))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            $ws = $workspaces->get($row->workspace_id);
            if ($ws === null) {
                continue;
            }
            $cost = round((float) $row->cost, 2);
            $budget = $this->plans->limit($ws, 'ai_budget_usd');
            $out[] = ['workspace_id' => $ws->publicId(), 'workspace' => $ws->name, 'cost_usd' => $cost, 'budget_usd' => $budget, 'pct' => $budget !== null && $budget > 0 ? (int) floor($cost * 100 / $budget) : null];
        }

        return $out;
    }
}
