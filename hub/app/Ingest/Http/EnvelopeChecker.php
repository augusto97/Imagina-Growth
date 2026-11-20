<?php

declare(strict_types=1);

namespace App\Ingest\Http;

use App\Domain\Sites\Models\Site;
use App\Ingest\Schema\BrowserEvents;
use App\Ingest\Schema\SchemaRegistry;
use Carbon\CarbonImmutable;

/**
 * Validación barata en el endpoint (paso 2 de hub/CLAUDE.md §5): sobre,
 * tamaño, nombre del evento y sitio. El esquema de properties se valida en el worker.
 */
final class EnvelopeChecker
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * @param  list<mixed>  $events  eventos decodificados como objetos (stdClass)
     * @return list<string> eventos aceptados re-codificados en JSON
     */
    public function check(array $events, Site $site, IngestResult $result, string $expectedSourceType = 'server'): array
    {
        $accepted = [];
        $maxBytes = (int) config('igr.ingest.max_event_bytes');
        $publicSiteId = $site->publicId();
        $now = CarbonImmutable::now('UTC');

        foreach ($events as $event) {
            $eventId = is_object($event) && isset($event->event_id) && is_string($event->event_id) ? $event->event_id : null;
            $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || ! $event instanceof \stdClass) {
                $result->reject($eventId, 'envelope');

                continue;
            }
            if (strlen($json) > $maxBytes) {
                $result->reject($eventId, 'too_large');

                continue;
            }
            if ($this->schemas->validateEnvelope($event) !== null) {
                $result->reject($eventId, 'envelope');

                continue;
            }
            if (! $this->schemas->isIngestible($event->event)) {
                $result->reject($eventId, 'unknown_event');

                continue;
            }
            if ($event->source->site_id !== $publicSiteId) {
                $result->reject($eventId, 'site_mismatch');

                continue;
            }
            $isBrowser = $event->source->type === 'browser';
            if (($expectedSourceType === 'browser') !== $isBrowser || $event->source->type === 'hub') {
                $result->reject($eventId, 'envelope');

                continue;
            }
            // Con la clave pública nadie puede enviar pedidos, clientes ni formularios (eventos.md §3).
            if ($isBrowser && ! BrowserEvents::allows($event->event)) {
                $result->reject($eventId, 'not_allowed');

                continue;
            }
            $occurred = $this->clampOccurredAt($event, $now, $isBrowser);
            if ($occurred === false) {
                $result->reject($eventId, 'stale');

                continue;
            }
            if ($occurred === true) {
                $json = (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $accepted[] = $json;
            $result->accepted++;
        }

        return $accepted;
    }

    /**
     * Una fecha del futuro (reloj adelantado) pasa a la de recepción: así no queda «último evento»
     * dentro de días ni rompe las cachés de los tableros. Del navegador, un evento de hace más de
     * 7 días se rechaza; la importación del plugin sí trae historia.
     *
     * @return bool|null null = sin cambios, true = se corrigió la fecha, false = se rechaza
     */
    private function clampOccurredAt(\stdClass $event, CarbonImmutable $now, bool $isBrowser): ?bool
    {
        try {
            $at = CarbonImmutable::parse((string) $event->occurred_at)->utc();
        } catch (\Throwable) {
            return false;
        }
        if ($at->greaterThan($now->addSeconds(BrowserEvents::FUTURE_TOLERANCE_SECONDS))) {
            $event->occurred_at = $now->format('Y-m-d\\TH:i:s.v\\Z');

            return true;
        }
        if ($isBrowser && $at->lessThan($now->subSeconds(BrowserEvents::MAX_AGE_SECONDS))) {
            return false;
        }

        return null;
    }
}
