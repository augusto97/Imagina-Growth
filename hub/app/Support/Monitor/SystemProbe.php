<?php

declare(strict_types=1);

namespace App\Support\Monitor;

use App\Analytics\Ch;
use App\Ingest\IngestStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Lo que mide `growth:monitor` (docs/despliegue.md §6): dependencias, streams de ingesta, Horizon y colas.
 * Cada método nunca lanza: si algo no responde, lo dice en el resultado. Las pruebas lo reemplazan con una
 * subclase para simular estados.
 */
class SystemProbe
{
    public function __construct(private readonly Ch $ch, private readonly IngestStream $stream) {}

    /** @return array{mariadb: bool, clickhouse: bool, redis: bool} */
    public function dependencies(): array
    {
        return [
            'mariadb' => $this->check(fn () => DB::select('SELECT 1') !== []),
            'clickhouse' => $this->check(fn () => $this->ch->ping()),
            'redis' => $this->check(fn () => (bool) $this->stream->connection()->command('ping')),
        ];
    }

    /**
     * Estado de cada shard del stream de ingesta: largo, pendientes (entregados sin confirmar), atraso (aún
     * sin entregar al grupo) y la antigüedad del evento más viejo sin procesar. null si Redis no responde.
     *
     * @return list<array{shard: int, length: int, pending: int, lag: int, oldest_seconds: int}>|null
     */
    public function streams(): ?array
    {
        try {
            /** @var \Redis $redis */
            $redis = $this->stream->connection()->client();
            $group = (string) config('igr.ingest.consumer_group');
            $now = microtime(true) * 1000;
            $out = [];
            for ($shard = 0; $shard < $this->stream->shards(); $shard++) {
                $key = $this->stream->key($shard);
                $length = (int) $redis->xLen($key);
                $pending = 0;
                $oldest = null;
                $lag = 0;
                $summary = $length > 0 || $redis->exists($key) ? $redis->xPending($key, $group) : false;
                if (is_array($summary)) {
                    $pending = (int) ($summary[0] ?? 0);
                    if (isset($summary[1]) && is_string($summary[1])) {
                        $oldest = (int) explode('-', $summary[1])[0];
                    }
                }
                $groups = $redis->exists($key) ? $redis->xInfo('GROUPS', $key) : [];
                $lastDelivered = null;
                foreach (is_array($groups) ? $groups : [] as $g) {
                    if (is_array($g) && ($g['name'] ?? null) === $group) {
                        $lag = (int) ($g['lag'] ?? 0);
                        $lastDelivered = is_string($g['last-delivered-id'] ?? null) ? $g['last-delivered-id'] : null;
                    }
                }
                if ($groups === [] && $length > 0) {
                    $lag = $length; // sin grupo todavía: nada se entregó
                }
                // El primer evento sin entregar también cuenta para la antigüedad.
                if ($lag > 0) {
                    $next = $redis->xRange($key, $lastDelivered !== null ? '('.$lastDelivered : '-', '+', 1);
                    if (is_array($next) && $next !== []) {
                        $id = (string) array_key_first($next);
                        $ms = (int) explode('-', $id)[0];
                        $oldest = $oldest === null ? $ms : min($oldest, $ms);
                    }
                }
                $out[] = [
                    'shard' => $shard,
                    'length' => $length,
                    'pending' => $pending,
                    'lag' => $lag,
                    'oldest_seconds' => $oldest === null ? 0 : max(0, (int) round(($now - $oldest) / 1000)),
                ];
            }

            return $out;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Memoria del Redis de colas (`noeviction`): lleno, deja de aceptar eventos y trabajos.
     *
     * @return array{used_mb: int, max_mb: int, used_pct: int}|null
     */
    public function queueMemory(): ?array
    {
        try {
            /** @var \Redis $redis */
            $redis = $this->stream->connection()->client();
            $info = $redis->info('memory');
            $used = (int) ($info['used_memory'] ?? 0);
            $max = (int) ($info['maxmemory'] ?? 0);

            return [
                'used_mb' => intdiv($used, 1048576),
                'max_mb' => intdiv($max, 1048576),
                'used_pct' => $max > 0 ? (int) round($used * 100 / $max) : 0,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** `running`, `paused` o `inactive` (como `horizon:status`). */
    public function horizon(): string
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            return 'inactive';
        }
        if ($masters === []) {
            return 'inactive';
        }
        foreach ($masters as $master) {
            if (($master->status ?? null) === 'paused') {
                return 'paused';
            }
        }

        return 'running';
    }

    /**
     * Trabajos pendientes por cola, para todas las colas que supervisa Horizon. null si Redis no responde.
     *
     * @return array<string, int>|null
     */
    public function queues(): ?array
    {
        $names = [];
        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $names[(string) $queue] = true;
            }
        }
        try {
            $connection = Queue::connection('redis');
            $sizes = [];
            foreach (array_keys($names) as $queue) {
                $sizes[$queue] = (int) $connection->size($queue);
            }

            return $sizes;
        } catch (Throwable) {
            return null;
        }
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
