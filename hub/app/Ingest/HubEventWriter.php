<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Analytics\Ch;
use App\Ingest\Jobs\WriteHubEventRows;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cómo llegan a ClickHouse los eventos del hub (R4). Dentro de una petición web (webhooks de Meta o SES, clics en
 * `/c/`, páginas públicas) nunca se espera a ClickHouse: las filas van a la cola `projections` y las escribe
 * `WriteHubEventRows`. En consola y en los trabajos de cola se insertan en el momento y, si ClickHouse falla, también
 * pasan a la cola: un evento del hub nunca se pierde ni tumba a quien lo emite.
 *
 * `igr.hub_events.write`: `auto` (por defecto), `queue` (siempre a la cola) o `direct` (siempre en el momento).
 */
class HubEventWriter
{
    public const CHUNK = 500;

    public function __construct(private readonly Ch $ch) {}

    /** @param list<array<string, mixed>> $rows */
    public function write(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        if ($this->deferred()) {
            $this->queue($rows);

            return;
        }
        try {
            $this->ch->insert('events', $rows, async: true);
        } catch (Throwable $e) {
            Log::warning('igr.hub_events.queued_after_failure', ['rows' => count($rows), 'error' => class_basename($e)]);
            $this->queue($rows);
        }
    }

    public function deferred(): bool
    {
        return match ((string) config('igr.hub_events.write', 'auto')) {
            'queue' => true,
            'direct' => false,
            default => ! app()->runningInConsole(),
        };
    }

    /** @param list<array<string, mixed>> $rows */
    private function queue(array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            WriteHubEventRows::dispatch($chunk)->onQueue('projections');
        }
    }
}
