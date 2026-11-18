<?php

declare(strict_types=1);

namespace App\Ingest\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de dominio publicado por la ingesta (paso 10). Lo escuchará el
 * TriggerRouter (flujos, segmentos en tiempo real, conversiones) en las fases 1 y 2.
 */
final class EventIngested
{
    use Dispatchable;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $siteId,
        public readonly string $event,
        public readonly string $eventId,
        public readonly ?string $contactId,
    ) {}
}
