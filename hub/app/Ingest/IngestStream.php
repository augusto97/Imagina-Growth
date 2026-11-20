<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Domain\Sites\Models\Site;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Streams de ingesta, particionados por workspace (hub/CLAUDE.md §5):
 * ingest:{shard} con shard = workspace.ch_id % shards, para mantener el orden
 * por espacio de trabajo y contacto.
 */
final class IngestStream
{
    public function connection(): Connection
    {
        return Redis::connection('ingest');
    }

    public function shards(): int
    {
        return max(1, (int) config('igr.ingest.shards'));
    }

    public function shardFor(int $workspaceChId): int
    {
        return $workspaceChId % $this->shards();
    }

    public function key(int $shard): string
    {
        return config('igr.ingest.stream_prefix').$shard;
    }

    /**
     * Agrega los eventos aceptados al stream del workspace del sitio.
     *
     * @param  list<string>  $events  cada evento en JSON
     * @param  array{ip?: string|null, ua?: string|null}  $transit  datos solo en tránsito (navegador)
     */
    public function push(Site $site, int $workspaceChId, array $events, array $transit = []): void
    {
        if ($events === []) {
            return;
        }
        $key = $this->key($this->shardFor($workspaceChId));
        $maxLen = (int) config('igr.ingest.stream_maxlen');
        $received = (string) (int) floor(microtime(true) * 1000);
        $base = [
            'ws' => $site->workspace_id,
            'wsc' => (string) $workspaceChId,
            'site' => $site->id,
            'sitec' => (string) $site->ch_id,
            'rcv' => $received,
            'ip' => (string) ($transit['ip'] ?? ''),
            'ua' => (string) ($transit['ua'] ?? ''),
        ];

        /** @var \Redis $client */
        $client = $this->connection()->client();
        $pipe = $client->multi(\Redis::PIPELINE);
        foreach ($events as $json) {
            $pipe->xAdd($key, '*', $base + ['e' => $json], $maxLen, true);
        }
        $pipe->exec();
    }

    /**
     * Borra del stream lo que el grupo ya confirmó (XACK no borra): deja desde la entrada
     * pendiente más vieja o, si no hay pendientes, desde la última entregada. Lo que nadie ha
     * leído nunca se toca.
     *
     * @return int entradas borradas
     */
    public function trimAcknowledged(int $shard, string $group): int
    {
        /** @var \Redis $client */
        $client = $this->connection()->client();
        $key = $this->key($shard);
        $groups = $client->xInfo('GROUPS', $key);
        if (! is_array($groups) || $groups === []) {
            return 0;
        }
        $threshold = null;
        foreach ($groups as $info) {
            if (! is_array($info)) {
                continue;
            }
            $delivered = (string) ($info['last-delivered-id'] ?? '0-0');
            $threshold = $threshold === null || self::idLess($delivered, $threshold) ? $delivered : $threshold;
        }
        $summary = $client->xPending($key, $group);
        if (is_array($summary) && (int) ($summary[0] ?? 0) > 0 && is_string($summary[1] ?? null)) {
            $oldestPending = $summary[1];
            $threshold = $threshold === null || self::idLess($oldestPending, $threshold) ? $oldestPending : $threshold;
        }
        if ($threshold === null || $threshold === '0-0') {
            return 0;
        }

        return (int) $client->rawCommand('XTRIM', $key, 'MINID', $threshold);
    }

    private static function idLess(string $a, string $b): bool
    {
        [$am, $as] = array_map('intval', explode('-', $a.'-0'));
        [$bm, $bs] = array_map('intval', explode('-', $b.'-0'));

        return $am < $bm || ($am === $bm && $as < $bs);
    }
}
