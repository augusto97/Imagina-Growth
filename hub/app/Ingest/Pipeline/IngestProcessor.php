<?php

declare(strict_types=1);

namespace App\Ingest\Pipeline;

use App\Domain\Commerce\Projectors\CatalogProjector;
use App\Domain\Commerce\Projectors\OrderProjector;
use App\Domain\Contacts\Jobs\RecalculateContactStats;
use App\Domain\Identity\IdentityInput;
use App\Domain\Identity\IdentityResolver;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Projectors\SiteHealthProjector;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Ingest\IngestStream;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pasos 4 a 10 del pipeline de ingesta (hub/CLAUDE.md §5) para un lote del
 * stream. Las escrituras en MariaDB son idempotentes; ClickHouse se escribe
 * después (IngestWorker) y solo entonces se confirma el lote y se marca el
 * dedupe. Así la entrega es «al menos una vez» y nada se pierde.
 */
final class IngestProcessor
{
    /** @var array<string, array{0: Workspace, 1: float}> */
    private array $workspaces = [];

    /** @var array<string, array{0: Site, 1: float}> */
    private array $sites = [];

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly EventRowMapper $mapper,
        private readonly IdentityResolver $identity,
        private readonly OrderProjector $orders,
        private readonly CatalogProjector $catalog,
        private readonly SiteHealthProjector $siteHealth,
        private readonly CurrentWorkspace $current,
        private readonly IngestStream $stream,
    ) {}

    /**
     * Todo el lote va en una transacción de MariaDB (un solo commit) y cada
     * evento en un savepoint: un evento con datos inválidos se rechaza sin
     * tumbar el lote. Un error transitorio (conexión, bloqueo) revierte el lote
     * completo y lanza la excepción: el worker no confirma esas entradas y se
     * reintentan.
     *
     * @param  list<IngestEntry>  $entries
     */
    public function process(array $entries): BatchOutcome
    {
        DB::beginTransaction();
        try {
            [$out, $recalculate, $touchedSites, $domainEvents] = $this->processInTransaction($entries);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack(0);
            throw $e;
        }

        // Después del commit: contadores del contacto (un job por contacto y lote, debounce de 5 s).
        foreach ($recalculate as $workspaceId => $contacts) {
            foreach (array_keys($contacts) as $contactId) {
                RecalculateContactStats::debounce($workspaceId, $contactId);
            }
        }
        foreach ($touchedSites as $siteId => $ms) {
            Site::withoutWorkspaceScope()->whereKey($siteId)->update(['last_event_at' => CarbonImmutable::createFromTimestampMs($ms)]);
        }
        // 10. Eventos de dominio, solo con los datos ya confirmados.
        foreach ($domainEvents as $domainEvent) {
            event($domainEvent);
        }

        return $out;
    }

    /**
     * @param  list<IngestEntry>  $entries
     * @return array{0: BatchOutcome, 1: array<string, array<string, true>>, 2: array<string, int>, 3: list<EventIngested>}
     */
    private function processInTransaction(array $entries): array
    {
        $out = new BatchOutcome;
        $redis = $this->stream->connection();
        $seenInBatch = [];
        $touchedSites = [];
        /** @var array<string, array<string, true>> espacio de trabajo => contactos con pedidos en el lote */
        $recalculate = [];
        /** @var list<EventIngested> $domainEvents */
        $domainEvents = [];

        foreach ($entries as $entry) {
            $envelope = json_decode($entry->json, true);
            if (! is_array($envelope) || ! isset($envelope['event_id'], $envelope['event'])) {
                $this->reject($entry, null, 'decode', 'JSON inválido en el stream');
                $out->ackIds[] = $entry->streamId;
                $out->rejected++;

                continue;
            }
            $entry->envelope = $envelope;
            $eventId = (string) $envelope['event_id'];
            $dedupeKey = RedisKey::for($entry->workspaceId, 'evt:'.$eventId);

            // 4. Dedupe (en el lote y contra los últimos 7 días).
            if (isset($seenInBatch[$dedupeKey]) || $redis->command('exists', [$dedupeKey])) {
                $out->ackIds[] = $entry->streamId;
                $out->duplicates++;

                continue;
            }

            // 5. Esquema de properties.
            $error = $this->schemas->validateProperties((string) $envelope['event'], json_decode($entry->json)->properties ?? new \stdClass);
            if ($error !== null) {
                $this->reject($entry, $envelope, 'schema', $error);
                $out->ackIds[] = $entry->streamId;
                $out->rejected++;

                continue;
            }

            $workspace = $this->workspace($entry->workspaceId);
            $site = $this->site($entry->siteId);
            if ($workspace === null || $site === null) {
                $this->reject($entry, $envelope, 'site_missing', 'El sitio o el espacio de trabajo ya no existe');
                $out->ackIds[] = $entry->streamId;
                $out->rejected++;

                continue;
            }

            try {
                // Savepoint por evento.
                $result = DB::transaction(fn () => $this->current->run($workspace, fn () => $this->handle($workspace, $site, $entry, $envelope)));
            } catch (Throwable $e) {
                Log::warning('igr.ingest.event_failed', [
                    'event_id' => $eventId, 'event' => $envelope['event'], 'transient' => $this->isTransient($e),
                    'error' => Pii::scrub(mb_substr($e->getMessage(), 0, 300)),
                ]);
                if ($this->isTransient($e)) {
                    throw $e;
                }
                $this->reject($entry, $envelope, 'processing', mb_substr($e->getMessage(), 0, 500));
                $out->ackIds[] = $entry->streamId;
                $out->rejected++;

                continue;
            }

            $seenInBatch[$dedupeKey] = true;
            $out->eventRows[] = $result['row'];
            if ($result['identity_row'] !== null) {
                $out->identityRows[] = $result['identity_row'];
            }
            $out->dedupeKeys[] = $dedupeKey;
            $out->ackIds[] = $entry->streamId;
            $out->processed++;
            $touchedSites[$site->id] = $entry->receivedMs;
            if ($result['recalculate'] !== null) {
                $recalculate[$workspace->id][$result['recalculate']] = true;
            }
            if ($result['domain_event'] !== null) {
                $domainEvents[] = $result['domain_event'];
            }
        }

        return [$out, $recalculate, $touchedSites, $domainEvents];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{row: array<string, mixed>, identity_row: array<string, mixed>|null, recalculate: string|null, domain_event: EventIngested|null}
     */
    private function handle(Workspace $workspace, Site $site, IngestEntry $entry, array $envelope): array
    {
        $event = (string) $envelope['event'];
        $occurredAt = CarbonImmutable::parse((string) $envelope['occurred_at'])->utc();
        $isImport = ($envelope['source']['type'] ?? '') === 'import';

        // 7. Identidad (solo con identificadores; los eventos de catálogo y sitio no tienen persona).
        $contactId = null;
        $identityRow = null;
        if (! str_starts_with($event, 'catalog.') && ! str_starts_with($event, 'site.')) {
            $input = IdentityInput::fromEnvelope($envelope);
            if ($input->hasStrongIdentifier() || $input->anonymousId !== null) {
                $resolved = $this->identity->resolve($workspace, $site, $input, $occurredAt);
                $contactId = $resolved['contact']?->id;
                if ($resolved['linked_anonymous'] && $contactId !== null && $input->anonymousId !== null) {
                    $identityRow = [
                        'workspace_id' => $entry->workspaceChId,
                        'anonymous_id' => $input->anonymousId,
                        'contact_id' => $contactId,
                        'linked_at' => $occurredAt->format('Y-m-d H:i:s.v'),
                    ];
                }
            }
        }

        // 9. Proyección en MariaDB.
        if (str_starts_with($event, 'order.')) {
            $this->orders->project($workspace, $site, $envelope, $contactId);
        } elseif (str_starts_with($event, 'catalog.')) {
            $this->catalog->project($workspace, $site, $envelope);
        } elseif (str_starts_with($event, 'site.')) {
            $this->siteHealth->project($site, $envelope);
        }

        // 10. Evento de dominio: se publica después del commit. La importación no dispara nada.
        $domainEvent = $isImport ? null : new EventIngested(
            $workspace->id,
            $site->id,
            $event,
            (string) $envelope['event_id'],
            $contactId,
            $envelope,
            ($envelope['context']['ip'] ?? null) ?: ($entry->ip !== '' ? $entry->ip : null),
            ($envelope['context']['user_agent'] ?? null) ?: ($entry->ua !== '' ? $entry->ua : null),
        );

        // 6 y 8. Enriquecer y armar la fila de ClickHouse sin PII.
        $row = $this->mapper->map($envelope, [
            'ws_ch' => $entry->workspaceChId,
            'site_ch' => $entry->siteChId,
            'site_host' => $site->host,
            'received_ms' => $entry->receivedMs,
            'ua' => $entry->ua,
        ], $contactId);

        return [
            'row' => $row,
            'identity_row' => $identityRow,
            'recalculate' => $contactId !== null && str_starts_with($event, 'order.') ? $contactId : null,
            'domain_event' => $domainEvent,
        ];
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            // Errores de conexión o bloqueos: se reintentan. Violaciones de datos: no.
            return in_array((string) $e->getCode(), ['2002', '2006', '2013', 'HY000', '40001', '1205', '1213'], true)
                || str_contains($e->getMessage(), 'Deadlock')
                || str_contains($e->getMessage(), 'gone away');
        }

        return $e instanceof \RedisException || $e instanceof \PDOException;
    }

    /** @param array<string, mixed>|null $envelope */
    private function reject(IngestEntry $entry, ?array $envelope, string $reason, string $detail): void
    {
        $sample = null;
        if ($envelope !== null) {
            $sample = [
                'event' => $envelope['event'] ?? null,
                'source' => $envelope['source'] ?? null,
                'occurred_at' => $envelope['occurred_at'] ?? null,
                'property_keys' => is_array($envelope['properties'] ?? null) ? array_slice(array_keys($envelope['properties']), 0, 30) : [],
            ];
        }
        DB::table('ingest_rejections')->insert([
            'workspace_id' => $entry->workspaceId,
            'site_id' => $entry->siteId ?: null,
            'event_id' => isset($envelope['event_id']) ? mb_substr((string) $envelope['event_id'], 0, 36) : null,
            'event' => isset($envelope['event']) ? mb_substr((string) $envelope['event'], 0, 64) : null,
            'reason' => $reason,
            'detail' => Pii::scrub(mb_substr($detail, 0, 1000)),
            'sample' => $sample === null ? null : json_encode($sample),
            'created_at' => now(),
        ]);
    }

    private function workspace(string $id): ?Workspace
    {
        $hit = $this->workspaces[$id] ?? null;
        if ($hit !== null && $hit[1] > microtime(true)) {
            return $hit[0];
        }
        $ws = Workspace::query()->find($id);
        if ($ws !== null) {
            $this->workspaces[$id] = [$ws, microtime(true) + 60];
        }

        return $ws;
    }

    private function site(string $id): ?Site
    {
        $hit = $this->sites[$id] ?? null;
        if ($hit !== null && $hit[1] > microtime(true)) {
            return $hit[0];
        }
        $site = Site::withoutWorkspaceScope()->find($id);
        if ($site !== null) {
            $this->sites[$id] = [$site, microtime(true) + 60];
        }

        return $site;
    }

    public function forgetCaches(): void
    {
        $this->workspaces = [];
        $this->sites = [];
    }
}
