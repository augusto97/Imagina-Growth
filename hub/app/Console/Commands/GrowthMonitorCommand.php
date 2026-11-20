<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Monitor\SystemProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vigilancia de producción (docs/despliegue.md §6). Mide MariaDB, ClickHouse y Redis, el atraso de los
 * streams de ingesta, Horizon y las colas, e imprime el resultado en JSON. Con --ping avisa a los
 * heartbeats de Better Stack **solo de lo que está bien**: si algo falla no avisa, y Better Stack alerta
 * cuando el heartbeat deja de llegar (así también alerta si el servidor entero se cae).
 *
 * Corre cada minuto desde growth-monitor.timer: growth-php artisan growth:monitor --ping
 */
final class GrowthMonitorCommand extends Command
{
    protected $signature = 'growth:monitor {--ping : Avisa a los heartbeats de Better Stack de lo que está bien}';

    protected $description = 'Revisa dependencias, streams de ingesta y colas, y avisa a Better Stack';

    public function handle(SystemProbe $probe): int
    {
        $deps = $probe->dependencies();
        $streams = $probe->streams();
        $horizon = $probe->horizon();
        $queues = $probe->queues();
        $memory = $probe->queueMemory();

        $backlog = $streams === null ? null : array_sum(array_map(fn (array $s) => $s['lag'] + $s['pending'], $streams));
        $oldest = $streams === null ? null : max([0, ...array_column($streams, 'oldest_seconds')]);
        $queued = $queues === null ? null : array_sum($queues);

        $verdicts = [
            'health' => ! in_array(false, $deps, true),
            'streams' => $deps['redis'] && $backlog !== null && $oldest !== null
                && $backlog < (int) config('igr-monitor.max_stream_lag')
                && $oldest < (int) config('igr-monitor.max_stream_age_seconds')
                && ($memory === null || $memory['used_pct'] < (int) config('igr-monitor.max_redis_memory_pct')),
            'queues' => $horizon === 'running' && $queued !== null && $queued < (int) config('igr-monitor.max_queue_backlog'),
        ];

        $pinged = [];
        if ($this->option('ping')) {
            foreach ($verdicts as $name => $ok) {
                $url = config("igr-monitor.heartbeats.{$name}");
                if (! $ok || ! is_string($url) || $url === '') {
                    continue;
                }
                try {
                    Http::timeout(10)->retry(2, 500, throw: false)->get($url);
                    $pinged[] = $name;
                } catch (Throwable) {
                    // Sin red hacia Better Stack: el heartbeat no llega y Better Stack alerta solo.
                }
            }
        }

        $this->line((string) json_encode([
            'status' => in_array(false, $verdicts, true) ? 'degraded' : 'ok',
            'checks' => $deps,
            'streams' => ['backlog' => $backlog, 'oldest_seconds' => $oldest, 'shards' => $streams],
            'horizon' => $horizon,
            'queues' => ['backlog' => $queued, 'sizes' => $queues],
            'redis_memory' => $memory,
            'verdicts' => $verdicts,
            'pinged' => $pinged,
            'time' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        return in_array(false, $verdicts, true) ? self::FAILURE : self::SUCCESS;
    }
}
