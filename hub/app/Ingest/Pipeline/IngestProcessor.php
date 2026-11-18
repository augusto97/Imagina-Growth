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
     * @param  list<IngestEntry>  $entries
     */
    public function process(array $entries, int $maxAttempts = 5): BatchOutcome
    {
        $out = new BatchOutcome;
        $redis = $this->stream->connection();
        $seenInBatch = [];
        $touchedSites = [];

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
                $result = $this->current->run($workspace, fn () => $this->handle($workspace, $site, $entry, $envelope));
            } catch (Throwable $e) {
                $attempts = (int) $redis->command('incr', ['ingest:attempts:'.$entry->streamId]);
                $redis->command('expire', ['ingest:attempts:'.$entry->streamId, 86400]);
                Log::warning('igr.ingest.event_failed', [
                    'event_id' => $eventId, 'event' => $envelope['event'], 'attempt' => $attempts,
                    'error' => Pii::scrub(mb_substr($e->getMessage(), 0, 300)),
                ]);
                if ($attempts >= $maxAttempts || ! $this->isTransient($e)) {
                    $this->reject($entry, $envelope, 'processing', mb_substr($e->getMessage(), 0, 500));
                    $out->ackIds[] = $entry->streamId;
                    $out->rejected++;
                } else {
                    $out->retryIds[] = $entry->streamId;
                }

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
        }

        foreach ($touchedSites as $siteId => $ms) {
            Site::withoutWorkspaceScope()->whereKey($siteId)->update(['last_event_at' => CarbonImmutable::createFromTimestampMs($ms)]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{row: array<string, mixed>, identity_row: array<string, mixed>|null}
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
        DB::transaction(function () use ($workspace, $site, $envelope, $event, $contactId): void {
            if (str_starts_with($event, 'order.')) {
                $this->orders->project($workspace, $site, $envelope, $contactId);
            } elseif (str_starts_with($event, 'catalog.')) {
                $this->catalog->project($workspace, $site, $envelope);
            } elseif (str_starts_with($event, 'site.')) {
                $this->siteHealth->project($site, $envelope);
            }
        });
        if ($contactId !== null && str_starts_with($event, 'order.')) {
            RecalculateContactStats::debounce($workspace->id, $contactId);
        }

        // 10. Evento de dominio (TriggerRouter en las fases 1 y 2). La importación no dispara nada.
        if (! $isImport) {
            event(new EventIngested($workspace->id, $site->id, $event, (string) $envelope['event_id'], $contactId));
        }

        // 6 y 8. Enriquecer y armar la fila de ClickHouse sin PII.
        $row = $this->mapper->map($envelope, [
            'ws_ch' => $entry->workspaceChId,
            'site_ch' => $entry->siteChId,
            'site_host' => $site->host,
            'received_ms' => $entry->receivedMs,
            'ua' => $entry->ua,
        ], $contactId);

        return ['row' => $row, 'identity_row' => $identityRow];
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
