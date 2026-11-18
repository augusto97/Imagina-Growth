<?php

declare(strict_types=1);

namespace App\Ingest\Console;

use App\Analytics\Ch;
use App\Ingest\IngestStream;
use App\Ingest\Pipeline\BatchOutcome;
use App\Ingest\Pipeline\IngestEntry;
use App\Ingest\Pipeline\IngestProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RedisException;
use Throwable;

/**
 * IngestWorker (hub/CLAUDE.md §5): consume ingest:{shard} con un consumer
 * group, procesa los lotes y escribe en ClickHouse cada 1 s o 5.000 filas.
 * Confirma (XACK) solo después de escribir. En producción corre un proceso
 * por shard (--shard=N); en local, uno para todos.
 */
final class IngestWorkCommand extends Command
{
    protected $signature = 'igr:ingest:work
        {--shard=* : Shards a consumir (por defecto todos)}
        {--once : Procesa lo pendiente y termina (pruebas y E2E)}
        {--max-time=3600 : Segundos antes de salir para que el supervisor lo reinicie}
        {--consumer= : Nombre del consumidor (por defecto host:pid)}';

    protected $description = 'Consume los Redis Streams de ingesta y escribe en ClickHouse y MariaDB';

    private bool $stop = false;

    /** @var list<array<string, mixed>> */
    private array $eventBuffer = [];

    /** @var list<array<string, mixed>> */
    private array $identityBuffer = [];

    /** @var list<string> */
    private array $dedupeBuffer = [];

    /** @var array<int, list<string>> */
    private array $ackBuffer = [];

    private ?float $bufferSince = null;

    /** @var array<string, true> IDs leídos y todavía sin confirmar en este proceso */
    private array $inFlight = [];

    public function handle(IngestStream $stream, IngestProcessor $processor, Ch $ch): int
    {
        $shards = array_values(array_map('intval', (array) $this->option('shard')));
        if ($shards === []) {
            $shards = range(0, $stream->shards() - 1);
        }
        $group = (string) config('igr.ingest.consumer_group');
        $consumer = (string) ($this->option('consumer') ?: gethostname().':'.getmypid());
        $once = (bool) $this->option('once');
        $deadline = microtime(true) + (int) $this->option('max-time');
        $readCount = (int) config('igr.ingest.worker_read_count');
        $flushRows = (int) config('igr.ingest.clickhouse_flush_rows');
        $flushMs = (int) config('igr.ingest.clickhouse_flush_ms');
        $blockMs = $once ? 0 : max(50, intdiv((int) config('igr.ingest.worker_block_ms'), count($shards)));

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        /** @var \Redis $redis */
        $redis = $stream->connection()->client();
        foreach ($shards as $shard) {
            $this->ensureGroup($redis, $stream->key($shard), $group);
        }

        $totals = ['processed' => 0, 'duplicates' => 0, 'rejected' => 0];
        $failures = 0;
        while (! $this->stop && microtime(true) < $deadline) {
            $readAny = false;
            foreach ($shards as $shard) {
                $key = $stream->key($shard);
                $entries = $this->claimStale($redis, $key, $group, $consumer, $once ? 0 : 30_000, $readCount);
                if ($entries === []) {
                    $entries = $this->readNew($redis, $key, $group, $consumer, $readCount, $blockMs);
                }
                if ($entries === []) {
                    continue;
                }
                $readAny = true;
                try {
                    $outcome = $processor->process($entries);
                } catch (Throwable $e) {
                    // Lote revertido y sin confirmar: se reclamará y reintentará.
                    Log::error('igr.ingest.batch_failed', ['shard' => $shard, 'entries' => count($entries), 'error' => mb_substr($e->getMessage(), 0, 300)]);
                    $this->error('Lote revertido; se reintentará: '.mb_substr($e->getMessage(), 0, 200));
                    if ($once && ++$failures >= 5) {
                        return self::FAILURE;
                    }
                    sleep(1);

                    continue;
                }
                $this->buffer($shard, $outcome);
                foreach ($totals as $k => $v) {
                    $totals[$k] = $v + $outcome->{$k};
                }
                if (count($this->eventBuffer) >= $flushRows) {
                    $this->flush($ch, $redis, $stream, $group);
                }
            }

            $age = $this->bufferSince === null ? 0 : (microtime(true) - $this->bufferSince) * 1000;
            if (! $readAny || $age >= $flushMs) {
                $this->flush($ch, $redis, $stream, $group);
            }
            if ($once && ! $readAny && $this->pendingCount($redis, $stream, $shards, $group) === 0) {
                break;
            }
            if ($once && ! $readAny) {
                usleep(200_000);
            }
        }
        $this->flush($ch, $redis, $stream, $group);

        $this->info(sprintf('Procesados: %d · duplicados: %d · rechazados: %d', $totals['processed'], $totals['duplicates'], $totals['rejected']));

        return self::SUCCESS;
    }

    private function buffer(int $shard, BatchOutcome $outcome): void
    {
        if ($outcome->ackIds === [] && $outcome->eventRows === []) {
            return;
        }
        $this->bufferSince ??= microtime(true);
        array_push($this->eventBuffer, ...$outcome->eventRows);
        array_push($this->identityBuffer, ...$outcome->identityRows);
        array_push($this->dedupeBuffer, ...$outcome->dedupeKeys);
        $this->ackBuffer[$shard] = array_merge($this->ackBuffer[$shard] ?? [], $outcome->ackIds);
        foreach ($outcome->ackIds as $id) {
            $this->inFlight[$id] = true;
        }
    }

    private function flush(Ch $ch, \Redis $redis, IngestStream $stream, string $group): void
    {
        if ($this->eventBuffer === [] && $this->ackBuffer === []) {
            $this->bufferSince = null;

            return;
        }
        try {
            $ch->insert('events', $this->eventBuffer);
            $ch->insert('identity_map', $this->identityBuffer);
        } catch (Throwable $e) {
            // Sin confirmar: las entradas siguen pendientes y se reclaman en 30 s.
            Log::error('igr.ingest.clickhouse_failed', ['rows' => count($this->eventBuffer), 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->error('ClickHouse falló; se reintentará: '.mb_substr($e->getMessage(), 0, 200));
            $this->resetBuffers();
            sleep(1);

            return;
        }

        $ttl = (int) config('igr.ingest.dedupe_ttl_seconds');
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach ($this->dedupeBuffer as $key) {
            $pipe->set($key, '1', ['ex' => $ttl]);
        }
        foreach ($this->ackBuffer as $shard => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $pipe->xAck($stream->key($shard), $group, $chunk);
            }
        }
        $pipe->exec();
        $this->resetBuffers();
    }

    private function resetBuffers(): void
    {
        $this->eventBuffer = [];
        $this->identityBuffer = [];
        $this->dedupeBuffer = [];
        $this->ackBuffer = [];
        $this->inFlight = [];
        $this->bufferSince = null;
    }

    private function ensureGroup(\Redis $redis, string $key, string $group): void
    {
        try {
            $redis->xGroup('CREATE', $key, $group, '0', true);
        } catch (RedisException $e) {
            if (! str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
        $redis->clearLastError();
    }

    /** @return list<IngestEntry> */
    private function readNew(\Redis $redis, string $key, string $group, string $consumer, int $count, int $blockMs): array
    {
        $res = $blockMs > 0
            ? $redis->xReadGroup($group, $consumer, [$key => '>'], $count, $blockMs)
            : $redis->xReadGroup($group, $consumer, [$key => '>'], $count);

        return is_array($res) ? $this->entries($res[$key] ?? []) : [];
    }

    /**
     * Reclama entradas pendientes de otros consumidores (o de una ejecución
     * anterior) que llevan más de $minIdleMs sin confirmarse.
     *
     * @return list<IngestEntry>
     */
    private function claimStale(\Redis $redis, string $key, string $group, string $consumer, int $minIdleMs, int $count): array
    {
        $pending = $redis->xPending($key, $group, '-', '+', $count);
        if (! is_array($pending) || $pending === []) {
            return [];
        }
        $ids = [];
        foreach ($pending as $p) {
            if ((int) $p[2] >= $minIdleMs && ! isset($this->inFlight[(string) $p[0]])) {
                $ids[] = (string) $p[0];
            }
        }
        if ($ids === []) {
            return [];
        }
        $claimed = $redis->xClaim($key, $group, $consumer, $minIdleMs, $ids, []);

        return is_array($claimed) ? $this->entries($claimed) : [];
    }

    /**
     * @param  array<string, array<string, string>>  $raw
     * @return list<IngestEntry>
     */
    private function entries(array $raw): array
    {
        $out = [];
        foreach ($raw as $id => $fields) {
            if (is_array($fields)) {
                $out[] = IngestEntry::fromStream((string) $id, $fields);
            }
        }

        return $out;
    }

    /** @param list<int> $shards */
    private function pendingCount(\Redis $redis, IngestStream $stream, array $shards, string $group): int
    {
        $total = 0;
        foreach ($shards as $shard) {
            $summary = $redis->xPending($stream->key($shard), $group);
            $total += is_array($summary) ? (int) ($summary[0] ?? 0) : 0;
        }

        return $total;
    }
}
