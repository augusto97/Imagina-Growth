<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Analytics\Ch;
use App\Http\Support\HealthAccess;
use App\Ingest\IngestStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /health (hub/CLAUDE.md §16): MariaDB, ClickHouse, Redis y retraso del
 * stream de ingesta. 503 si algo crítico falla. Sin datos de clientes. En
 * público solo dice ok o degraded; el detalle, desde la máquina o con el token
 * (S16, HealthAccess).
 */
final class HealthController
{
    public function __invoke(Request $request, Ch $ch, IngestStream $stream): JsonResponse
    {
        $checks = [
            'mariadb' => $this->check(fn () => DB::select('SELECT 1') !== []),
            'clickhouse' => $this->check(fn () => $ch->ping()),
            'redis' => $this->check(fn () => (bool) $stream->connection()->command('ping')),
        ];
        $ok = ! in_array(false, $checks, true);
        if (! HealthAccess::detailed($request)) {
            return response()->json(['status' => $ok ? 'ok' : 'degraded'], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
        }

        $ingest = [];
        try {
            /** @var \Redis $redis */
            $redis = $stream->connection()->client();
            $group = (string) config('igr.ingest.consumer_group');
            for ($shard = 0; $shard < $stream->shards(); $shard++) {
                $key = $stream->key($shard);
                $pending = $redis->xPending($key, $group);
                $oldest = is_array($pending) && isset($pending[1]) && is_string($pending[1]) ? (int) explode('-', $pending[1])[0] : null;
                $ingest[$shard] = [
                    'length' => (int) $redis->xLen($key),
                    'pending' => is_array($pending) ? (int) ($pending[0] ?? 0) : 0,
                    'lag_seconds' => $oldest === null ? 0 : max(0, (int) round(microtime(true) - $oldest / 1000)),
                ];
            }
        } catch (Throwable) {
            $ingest = null;
        }

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'ingest' => $ingest,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
