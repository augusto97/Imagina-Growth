<?php

declare(strict_types=1);

namespace App\Domain\Sites\Health;

use App\Analytics\Ch;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Semáforo de salud de un sitio (docs/auditoria-2026-11.md U4 y F6): al día, con atraso o sin datos, con las causas
 * concretas para que el panel diga qué hacer. Señales:
 *
 * - Eventos del servidor (el plugin) y del navegador (el tracker) en ClickHouse, de los últimos 7 días.
 * - El heartbeat horario del plugin (`sites.health`): WP-Cron atrasado y cola de envío trabada.
 * - La versión del plugin frente a `igr.min_plugin_version`.
 * - Rechazos de ingesta de las últimas 24 horas y la importación histórica detenida o con error.
 *
 * `igr:sites:health` (cada 10 minutos) guarda `sites.status` = degraded cuando el sitio no está al día y lo devuelve a
 * connected cuando se recupera. Las causas se calculan al vuelo en el panel (caché de un minuto por espacio de trabajo).
 */
final class SiteHealth
{
    public const OK = 'ok';

    public const LAGGING = 'lagging';

    public const NO_DATA = 'no_data';

    /** Sin eventos del servidor por más de esto, el sitio está atrasado (el heartbeat es cada hora). */
    public const SERVER_LAG_HOURS = 3;

    /** …y por más de esto, no está enviando nada. */
    public const SERVER_SILENT_HOURS = 24;

    /** Sin visitas del navegador por más de esto, el código de seguimiento puede no estar cargando. */
    public const BROWSER_LAG_HOURS = 48;

    /** La cola del plugin se vacía cada minuto: un pendiente más viejo que esto es WP-Cron atrasado. */
    public const OUTBOX_LAG_MINUTES = 30;

    /** La importación reporta su avance en cada lote. */
    public const BACKFILL_STALL_MINUTES = 30;

    /** Causas que dejan el sitio sin datos (no solo atrasado). */
    private const NO_DATA_CAUSES = ['disconnected', 'pending', 'never_received', 'server_silent'];

    /**
     * El semáforo de varios sitios del mismo espacio de trabajo.
     *
     * @param  iterable<Site>  $sites
     * @return array<string, array{state: string, causes: list<array{key: string, severity: string, params: array<string, int|string>}>, checked_at: string}>
     */
    public function forSites(Workspace $workspace, iterable $sites, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $list = [];
        foreach ($sites as $site) {
            $list[] = $site;
        }
        if ($list === []) {
            return [];
        }
        $signals = $this->signals($workspace, $list);
        $out = [];
        foreach ($list as $site) {
            $out[$site->id] = self::evaluate($site, $signals[$site->id] ?? [], $now);
        }

        return $out;
    }

    /** @return array{state: string, causes: list<array{key: string, severity: string, params: array<string, int|string>}>, checked_at: string} */
    public function forSite(Workspace $workspace, Site $site): array
    {
        return $this->forSites($workspace, [$site])[$site->id];
    }

    /**
     * Las señales que no están en la fila del sitio, en dos consultas para todo el espacio de trabajo (caché de 1 min).
     *
     * @param  list<Site>  $sites
     * @return array<string, array{server_at?: string|null, browser_at?: string|null, clickhouse?: bool, rejections_24h?: int}>
     */
    private function signals(Workspace $workspace, array $sites): array
    {
        $ids = array_map(fn (Site $s) => $s->id, $sites);
        sort($ids);
        $key = 'ws:'.$workspace->id.':sites:health:'.md5(implode(',', $ids));

        /** @var array<string, array{server_at?: string|null, browser_at?: string|null, clickhouse?: bool, rejections_24h?: int}> */
        return Cache::remember($key, 60, function () use ($workspace, $sites, $ids): array {
            $out = array_fill_keys($ids, ['server_at' => null, 'browser_at' => null, 'clickhouse' => false, 'rejections_24h' => 0]);
            $byCh = [];
            foreach ($sites as $site) {
                $byCh[(int) $site->ch_id] = $site->id;
            }
            try {
                $rows = Ch::forWorkspace($workspace)->select(
                    "SELECT site_id, source_type, toString(max(received_at)) AS last FROM events
                     WHERE workspace_id = {workspace_id:UInt32} AND site_id IN {sites:Array(UInt32)} AND source_type IN ('server', 'browser')
                       AND occurred_at >= now() - INTERVAL 8 DAY AND received_at >= now() - INTERVAL 7 DAY
                     GROUP BY site_id, source_type",
                    ['sites' => '['.implode(',', array_keys($byCh)).']'],
                );
                foreach ($ids as $id) {
                    $out[$id]['clickhouse'] = true;
                }
                foreach ($rows as $row) {
                    $siteId = $byCh[(int) $row['site_id']] ?? null;
                    if ($siteId !== null) {
                        $out[$siteId][$row['source_type'] === 'browser' ? 'browser_at' : 'server_at'] = (string) $row['last'];
                    }
                }
            } catch (Throwable) {
                // ClickHouse caído: el semáforo usa solo lo que sabe MariaDB.
            }
            foreach (DB::table('ingest_rejections')->where('workspace_id', $workspace->id)->whereIn('site_id', $ids)
                ->where('created_at', '>=', now()->subDay())->selectRaw('site_id, COUNT(*) AS n')->groupBy('site_id')->get() as $row) {
                $out[(string) $row->site_id]['rejections_24h'] = (int) $row->n;
            }

            return $out;
        });
    }

    /**
     * Regla del semáforo. Pura: recibe el sitio, sus señales y la hora.
     *
     * @param  array{server_at?: string|null, browser_at?: string|null, clickhouse?: bool, rejections_24h?: int}  $signals
     * @return array{state: string, causes: list<array{key: string, severity: string, params: array<string, int|string>}>, checked_at: string}
     */
    public static function evaluate(Site $site, array $signals, CarbonImmutable $now): array
    {
        $causes = [];
        $add = function (string $key, string $severity, array $params = []) use (&$causes): void {
            $causes[] = ['key' => $key, 'severity' => $severity, 'params' => $params];
        };
        $hoursSince = fn (?CarbonImmutable $at) => $at === null ? null : (int) floor(max(0, $now->getTimestamp() - $at->getTimestamp()) / 3600);
        $parse = fn (mixed $v) => is_string($v) && $v !== '' ? CarbonImmutable::parse($v, 'UTC') : null;

        if ($site->status === Site::STATUS_DISCONNECTED) {
            $add('disconnected', 'error');

            return self::result($causes, $now);
        }
        if ($site->status === Site::STATUS_PENDING) {
            $add('pending', 'warning');

            return self::result($causes, $now);
        }

        $connectedAt = $site->connected_at !== null ? CarbonImmutable::instance($site->connected_at) : null;
        $lastEvent = $site->last_event_at !== null ? CarbonImmutable::instance($site->last_event_at) : null;
        $lastSeen = $site->last_seen_at !== null ? CarbonImmutable::instance($site->last_seen_at) : null;
        $health = $site->health ?? [];
        $heartbeat = $parse($health['heartbeat_at'] ?? null);
        $usesClickHouse = (bool) ($signals['clickhouse'] ?? false);

        if ($lastEvent === null && $heartbeat === null) {
            if ($connectedAt === null || $connectedAt->diffInMinutes($now) >= 15) {
                $add('never_received', 'error');
            }

            return self::result($causes, $now);
        }

        // Servidor: el último evento del plugin (ClickHouse) o, sin ClickHouse, el último contacto del sitio.
        $server = $usesClickHouse ? $parse($signals['server_at'] ?? null) : null;
        $server = self::latest($server, $heartbeat, $lastSeen, $usesClickHouse ? null : $lastEvent);
        $serverHours = $hoursSince($server);
        if ($serverHours === null || $serverHours >= self::SERVER_LAG_HOURS) {
            $add($serverHours === null || $serverHours >= self::SERVER_SILENT_HOURS ? 'server_silent' : 'server_late', 'error', ['hours' => $serverHours ?? 168]);
        } else {
            // El plugin envía, pero sus tareas programadas van tarde: el heartbeat no llega o la cola no se vacía.
            $heartbeatHours = $hoursSince($heartbeat);
            $oldestPending = $parse($health['oldest_pending_at'] ?? null);
            $pendingMinutes = $oldestPending !== null && $heartbeat !== null ? (int) floor(max(0, $heartbeat->getTimestamp() - $oldestPending->getTimestamp()) / 60) : null;
            if ($heartbeatHours === null || $heartbeatHours >= self::SERVER_LAG_HOURS) {
                $add('cron_late', 'warning', ['hours' => $heartbeatHours ?? 0]);
            } elseif ($pendingMinutes !== null && $pendingMinutes >= self::OUTBOX_LAG_MINUTES && (int) ($health['outbox_pending'] ?? 0) > 0) {
                $add('outbox_late', 'warning', ['minutes' => $pendingMinutes, 'pending' => (int) $health['outbox_pending']]);
            }
        }

        // Navegador: sin visitas, los tableros de visitas y conversión salen en cero.
        if ($usesClickHouse) {
            $browser = $parse($signals['browser_at'] ?? null);
            $browserHours = $hoursSince($browser);
            if ($browser === null) {
                $add('browser_missing', 'warning');
            } elseif ($browserHours !== null && $browserHours >= self::BROWSER_LAG_HOURS) {
                $add('browser_late', 'warning', ['hours' => $browserHours]);
            }
        }

        $min = (string) config('igr.min_plugin_version', '0.0.0');
        $version = $site->getAttribute('plugin_version');
        if (is_string($version) && $version !== '' && version_compare($version, $min, '<')) {
            $add('plugin_outdated', 'warning', ['version' => $version, 'min' => $min]);
        }

        $rejections = (int) ($signals['rejections_24h'] ?? 0);
        if ($rejections > 0) {
            $add('rejections', 'warning', ['count' => $rejections]);
        }
        $dead = (int) ($health['outbox_dead'] ?? 0);
        if ($dead > 0) {
            $add('outbox_dead', 'warning', ['count' => $dead]);
        }

        $backfill = $site->backfill_state ?? [];
        $status = $backfill['status'] ?? null;
        if ($status === 'failed') {
            $add('backfill_failed', 'error');
        } elseif ($status === 'running' || $status === 'queued') {
            $reported = $parse($backfill['reported_at'] ?? null) ?? $parse($backfill['started_at'] ?? null);
            $minutes = $reported === null ? null : (int) floor(max(0, $now->getTimestamp() - $reported->getTimestamp()) / 60);
            if ($minutes !== null && $minutes >= self::BACKFILL_STALL_MINUTES) {
                $add('backfill_stalled', 'warning', ['minutes' => $minutes]);
            }
        }

        return self::result($causes, $now);
    }

    /**
     * @param  list<array{key: string, severity: string, params: array<string, int|string>}>  $causes
     * @return array{state: string, causes: list<array{key: string, severity: string, params: array<string, int|string>}>, checked_at: string}
     */
    private static function result(array $causes, CarbonImmutable $now): array
    {
        $noData = array_filter($causes, fn (array $c) => in_array($c['key'], self::NO_DATA_CAUSES, true));
        $state = $causes === [] ? self::OK : ($noData !== [] ? self::NO_DATA : self::LAGGING);
        // Primero lo más grave.
        usort($causes, fn (array $a, array $b) => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1));

        return ['state' => $state, 'causes' => $causes, 'checked_at' => $now->toIso8601String()];
    }

    private static function latest(?CarbonImmutable ...$dates): ?CarbonImmutable
    {
        $best = null;
        foreach ($dates as $d) {
            if ($d !== null && ($best === null || $d->greaterThan($best))) {
                $best = $d;
            }
        }

        return $best;
    }
}
