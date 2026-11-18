<?php

declare(strict_types=1);

namespace App\Ingest\Http;

use App\Domain\Sites\Models\Site;
use App\Ingest\Schema\SchemaRegistry;

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
            if (! $this->schemas->isKnownEvent($event->event)) {
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
            $accepted[] = $json;
            $result->accepted++;
        }

        return $accepted;
    }
}
