<?php

declare(strict_types=1);

namespace App\Ingest\Pipeline;

/** Resultado de procesar un lote: qué escribir en ClickHouse y qué confirmar. */
final class BatchOutcome
{
    /** @var list<array<string, mixed>> */
    public array $eventRows = [];

    /** @var list<array<string, mixed>> */
    public array $identityRows = [];

    /** @var list<string> claves de dedupe que se marcan después de escribir */
    public array $dedupeKeys = [];

    /** @var list<string> IDs del stream que se confirman (XACK) después de escribir */
    public array $ackIds = [];

    /** @var list<string> IDs del stream que quedan pendientes para reintentar */
    public array $retryIds = [];

    public int $processed = 0;

    public int $duplicates = 0;

    public int $rejected = 0;
}
