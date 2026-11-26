<?php

declare(strict_types=1);

namespace App\Ingest;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Ingest\Pipeline\EventRowMapper;
use App\Ingest\Schema\SchemaRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Emite los eventos que genera el hub (eventos.md §4.6): los valida contra su
 * esquema, los escribe en ClickHouse con HubEventWriter (en una petición web, por
 * la cola; si ClickHouse falla, también: R4) y publica EventIngested para los
 * listeners (flujos, segmentos en tiempo real).
 *
 * Además de los eventos exclusivos del hub, puede emitir los de `FROM_HUB_TOO`: los formularios del hub (las páginas
 * de captura, `form.submitted` con `form_plugin = igr_landing`, eventos.md §4.4). Salen con `source.type = hub`, así
 * que no crean el lead de origen `form` ni van a los anuncios (eso lo hace quien los emite), y llevan el `context`
 * de la visita (UTM, identificadores de clic, página) para la analítica.
 */
final class HubEvents
{
    /** Eventos que también llegan de los sitios y que el hub puede originar (formularios propios). */
    public const FROM_HUB_TOO = ['form.submitted'];

    public function __construct(
        private readonly HubEventWriter $writer,
        private readonly SchemaRegistry $schemas,
        private readonly EventRowMapper $mapper,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context  contexto de la visita (utm, click_ids, page, referral_code), solo para los de FROM_HUB_TOO
     * @return string event_id
     */
    public function emit(Workspace $workspace, string $event, array $properties, ?string $contactId, ?Site $site = null, ?CarbonInterface $at = null, ?string $eventId = null, ?string $anonymousId = null, array $context = []): string
    {
        $ids = $this->emitMany($workspace, [[
            'event' => $event, 'properties' => $properties, 'contact_id' => $contactId,
            'site' => $site, 'at' => $at, 'event_id' => $eventId, 'anonymous_id' => $anonymousId, 'context' => $context,
        ]]);

        return $ids[0];
    }

    /**
     * Inserta varios eventos en una sola escritura (p. ej. las altas y bajas de un segmento).
     *
     * @param  list<array{event: string, properties: array<string, mixed>, contact_id: string|null, site?: Site|null, at?: CarbonInterface|null, event_id?: string|null, anonymous_id?: string|null, context?: array<string, mixed>}>  $items
     * @return list<string>
     */
    public function emitMany(Workspace $workspace, array $items, bool $publish = true): array
    {
        $rows = [];
        $published = [];
        $ids = [];
        $now = (int) floor(microtime(true) * 1000);
        foreach ($items as $item) {
            $event = $item['event'];
            $fromHub = in_array($event, self::FROM_HUB_TOO, true);
            if (! SchemaRegistry::isHubOnly($event) && ! $fromHub) {
                throw new InvalidArgumentException("{$event} no es un evento del hub.");
            }
            $error = $this->schemas->validateProperties($event, json_decode((string) json_encode((object) $item['properties'])));
            if ($error !== null) {
                throw new InvalidArgumentException("Evento del hub inválido {$event}: {$error}");
            }
            $site = $item['site'] ?? null;
            $eventId = $item['event_id'] ?? (string) Str::uuid7();
            $envelope = [
                'schema_version' => 2,
                'event_id' => $eventId,
                'event' => $event,
                'occurred_at' => CarbonImmutable::instance($item['at'] ?? now())->toIso8601String(),
                'source' => ['type' => 'hub', 'platform' => 'hub', 'site_id' => $site?->publicId() ?? '', 'sdk' => 'hub/'.config('app.version', '1')],
                // El anonymous_id liga el evento a la navegación previa aunque aún no haya contacto.
                'identity' => isset($item['anonymous_id']) && $item['anonymous_id'] !== '' ? ['anonymous_id' => $item['anonymous_id']] : [],
                'context' => $fromHub ? (array) ($item['context'] ?? []) : [],
                'properties' => $item['properties'],
            ];
            $rows[] = $this->mapper->map($envelope, [
                'ws_ch' => $workspace->ch_id,
                'site_ch' => $site !== null ? $site->ch_id : 0,
                'site_host' => $site !== null ? $site->host : '',
                'received_ms' => $now,
                'ua' => '',
            ], $item['contact_id']);
            $published[] = new EventIngested($workspace->id, $site !== null ? $site->id : '', $event, $eventId, $item['contact_id'], $envelope);
            $ids[] = $eventId;
        }

        $this->writer->write($rows);
        if ($publish) {
            foreach ($published as $domainEvent) {
                event($domainEvent);
            }
        }

        return $ids;
    }
}
