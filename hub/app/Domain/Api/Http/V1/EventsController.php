<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use Dedoc\Scramble\Attributes\Group;
use App\Domain\Sites\Models\Site;
use App\Ingest\Http\EnvelopeChecker;
use App\Ingest\Http\IngestResult;
use App\Ingest\IngestStream;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Eventos del servidor de otro sistema (CRM, ERP, formularios externos): entran por la misma
 * ingesta que los del plugin, con `source.type = server` y validados contra el contrato
 * (eventos.md). Solo `custom.*`, `form.submitted` e `identify`: pedidos, carritos y catálogo
 * los manda el plugin.
 */
#[Group('Eventos', weight: 2)]
final class EventsController
{
    public const ALLOWED = ['form.submitted', 'identify'];

    public const MAX_EVENTS = 100;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Enviar eventos
     *
     * Hasta 100 eventos por petición en `events`. Cada uno: `event` (`custom.algo`, `form.submitted`
     * o `identify`), `properties` según el contrato, `identity` (correo, teléfono, `customer_ref`
     * o `anonymous_id`), y opcionales `event_id` (UUID; repetirlo no duplica), `occurred_at`
     * (ISO 8601), `site_id` y `context`. Responde 202 con los aceptados y los rechazados con su
     * motivo. Requiere `events:write`.
     */
    public function store(Request $request, EnvelopeChecker $checker, IngestStream $stream, SchemaRegistry $schemas): JsonResponse
    {
        $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['array'],
            'events.*.event' => ['required', 'string', 'max:64'],
            'events.*.event_id' => ['sometimes', 'uuid'],
            'events.*.occurred_at' => ['sometimes', 'date'],
            'events.*.site_id' => ['sometimes', 'string', 'max:40'],
            'events.*.identity' => ['sometimes', 'array'],
            'events.*.properties' => ['sometimes', 'array'],
            'events.*.context' => ['sometimes', 'array'],
        ]);
        $workspace = $this->current->get();
        $defaultSite = Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first();

        $result = new IngestResult;
        /** @var array<string, array{0: Site, 1: list<\stdClass>}> $bySite */
        $bySite = [];
        foreach ((array) $request->input('events') as $raw) {
            $raw = (array) $raw;
            $name = (string) $raw['event'];
            $eventId = isset($raw['event_id']) ? strtolower((string) $raw['event_id']) : (string) Str::uuid7();
            if (! str_starts_with($name, 'custom.') && ! in_array($name, self::ALLOWED, true)) {
                $result->reject($eventId, 'event_not_allowed');

                continue;
            }
            // El plugin deja la validación de propiedades al worker; aquí se avisa de una vez a quien llama.
            if ($schemas->isKnownEvent($name) && $schemas->validateProperties($name, json_decode((string) json_encode((object) ($raw['properties'] ?? [])))) !== null) {
                $result->reject($eventId, 'properties');

                continue;
            }
            $site = isset($raw['site_id']) ? Site::findByPublicId((string) $raw['site_id']) : $defaultSite;
            if ($site === null) {
                $result->reject($eventId, 'site_not_found');

                continue;
            }
            $envelope = [
                'schema_version' => 5,
                'event_id' => $eventId,
                'event' => $name,
                'occurred_at' => isset($raw['occurred_at']) ? date(DATE_RFC3339_EXTENDED, (int) strtotime((string) $raw['occurred_at'])) : now()->format(DATE_RFC3339_EXTENDED),
                'source' => ['type' => 'server', 'platform' => $site->platform === 'woocommerce' ? 'woocommerce' : 'wordpress', 'site_id' => $site->publicId(), 'sdk' => 'hub/api-v1'],
                'identity' => (object) array_intersect_key((array) ($raw['identity'] ?? []), array_flip(['anonymous_id', 'customer_ref', 'email', 'phone'])),
                'properties' => (object) ($raw['properties'] ?? []),
            ];
            if (isset($raw['context']) && is_array($raw['context']) && $raw['context'] !== []) {
                $envelope['context'] = $raw['context'];
            }
            $decoded = json_decode((string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (! $decoded instanceof \stdClass) {
                $result->reject($eventId, 'envelope');

                continue;
            }
            $bySite[$site->id] ??= [$site, []];
            $bySite[$site->id][1][] = $decoded;
        }

        foreach ($bySite as [$site, $events]) {
            $accepted = $checker->check($events, $site, $result, 'server');
            $stream->push($site, (int) $workspace->ch_id, $accepted);
        }

        if ($result->accepted === 0) {
            return new JsonResponse(['error' => ['code' => 'events_rejected', 'message' => __('Ningún evento pasó la validación del contrato.')], 'rejected' => $result->rejected], 422);
        }

        return new JsonResponse(['accepted' => $result->accepted, 'rejected' => $result->rejected], 202);
    }
}
