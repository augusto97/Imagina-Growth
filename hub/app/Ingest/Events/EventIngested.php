<?php

declare(strict_types=1);

namespace App\Ingest\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de dominio publicado por la ingesta (paso 10 de hub/CLAUDE.md §5),
 * después del commit del lote. Lo escuchan el TriggerRouter (flujos,
 * segmentos en tiempo real) y el ConversionRouter (anuncios). Los eventos de
 * importación (source.type = import) no se publican.
 *
 * Vive solo en memoria: `envelope` trae datos personales del evento original y
 * `ip`/`userAgent` son datos en tránsito. Un listener que los necesite fuera
 * del proceso (p. ej. un job encolado) debe cifrarlos (ShouldBeEncrypted) y no
 * guardarlos en tablas.
 */
final class EventIngested
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $envelope  sobre validado (eventos.md §2)
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $siteId,
        public readonly string $event,
        public readonly string $eventId,
        public readonly ?string $contactId,
        public readonly array $envelope = [],
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    public function isBrowser(): bool
    {
        return ($this->envelope['source']['type'] ?? null) === 'browser';
    }

    /** Consentimiento de marketing del evento (context.consent.marketing). */
    public function marketingConsent(): bool
    {
        return (bool) ($this->envelope['context']['consent']['marketing'] ?? false);
    }
}
