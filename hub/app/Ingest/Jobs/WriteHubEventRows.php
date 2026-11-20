<?php

declare(strict_types=1);

namespace App\Ingest\Jobs;

use App\Analytics\Ch;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escribe en ClickHouse filas de eventos del hub que no se insertaron en el momento (R4): las de una petición web
 * (webhooks, clics en /c/) y las que fallaron porque ClickHouse no respondía. Reintenta con espera creciente durante
 * 24 horas; si aun así falla, queda en `failed_jobs` (cifrado: las propiedades pueden llevar enlaces con tokens) para
 * reintentarlo a mano. La tabla `events` es ReplacingMergeTree por event_id: un reintento no duplica.
 */
final class WriteHubEventRows implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(public array $rows) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 60, 300, 600];
    }

    public function handle(Ch $ch): void
    {
        $ch->insert('events', $this->rows, async: true);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('igr.hub_events.write_failed', ['rows' => count($this->rows), 'error' => $e !== null ? class_basename($e) : null]);
    }
}
