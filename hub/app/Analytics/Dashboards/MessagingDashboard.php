<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Messaging\Coexistence\BusinessAppMessages;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 9. Mensajería (docs/auditoria-2026-11.md F11): enviados, entregados, leídos, aperturas, clics, respuestas, bajas,
 * rebotes y fallos, y las ventas atribuidas, por canal y por flujo o campaña, en el periodo.
 *
 * - Mensajes: los salientes creados en el periodo (`messages`), sin los que la política negó, saltó (grupo de
 *   control) o sigue aplazando: esos nunca salieron. Tampoco lo escrito desde la app del celular ni el historial
 *   copiado (coexistencia de WhatsApp): no salió por la plataforma.
 * - Aperturas reales y bajas: `message.opened` sin las de Apple MPP y `message.unsubscribed` de ClickHouse (como el
 *   reporte de campañas); sin ClickHouse, la columna `opened_at` y sin bajas.
 * - Ventas: pedidos pagados del periodo atribuidos a un mensaje (`orders.attributed_message_id`, hub/CLAUDE.md §9),
 *   por el canal del mensaje y por su flujo o campaña.
 */
final class MessagingDashboard extends Dashboard
{
    private const NOT_SENT = ['denied', 'skipped', 'deferred'];

    private const COUNTS = "COUNT(*) AS total,
        SUM(CASE WHEN m.sent_at IS NOT NULL OR m.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN m.delivered_at IS NOT NULL OR m.status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN m.read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count,
        SUM(CASE WHEN m.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
        SUM(CASE WHEN m.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
        SUM(CASE WHEN m.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
        SUM(CASE WHEN m.status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
        SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) AS failed";

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $events = $this->events($workspace, $period);
        $totals = $this->totals($workspace, $period, $filters, $events);
        if ($totals['sent'] === 0 && $totals['total'] === 0) {
            return ['available' => true, 'empty' => true, 'totals' => $totals, 'compare' => null, 'by_channel' => [], 'by_source' => [], 'series' => [],
                'opens_exclude_mpp' => $events !== null];
        }

        return [
            'available' => true,
            'empty' => false,
            'totals' => $totals,
            'compare' => $period->compare !== null ? $this->totals($workspace, $period->compare, $filters, $this->events($workspace, $period->compare)) : null,
            'by_channel' => $this->byChannel($workspace, $period, $filters, $events),
            'by_source' => $this->bySource($workspace, $period, $filters, $events),
            'series' => $this->series($workspace, $period),
            'opens_exclude_mpp' => $events !== null,
        ];
    }

    private function messages(Workspace $workspace, Period $period): Builder
    {
        return DB::table('messages as m')->where('m.workspace_id', $workspace->id)->where('m.direction', 'out')
            ->where('m.created_at', '>=', $period->fromUtc())->where('m.created_at', '<', $period->toUtc())
            ->whereNotIn('m.status', self::NOT_SENT)->whereNotIn('m.source_type', BusinessAppMessages::SOURCES);
    }

    /** Pedidos pagados del periodo atribuidos a un mensaje, con el canal y el origen del mensaje. */
    private function attributed(Workspace $workspace, Period $period, Filters $filters): Builder
    {
        return $this->paidOrders($workspace, $period, $filters, 'o')->whereNotNull('o.attributed_message_id')
            ->join('messages as am', function ($j) use ($workspace) {
                $j->on('am.id', '=', 'o.attributed_message_id')->where('am.workspace_id', $workspace->id);
            });
    }

    /**
     * @param  list<array{channel: string, flow: string, campaign: string, event: string, n: int}>|null  $events
     * @return array<string, int|float>
     */
    private function totals(Workspace $workspace, Period $period, Filters $filters, ?array $events): array
    {
        $row = $this->messages($workspace, $period)->selectRaw(self::COUNTS)->first();
        $sales = $this->attributed($workspace, $period, $filters)->selectRaw('COUNT(*) AS orders, COALESCE(SUM(o.total_ws), 0) AS revenue')->first();

        return $this->shape($row, $events === null ? null : self::sum($events, fn () => true), $sales);
    }

    /**
     * @param  list<array{channel: string, flow: string, campaign: string, event: string, n: int}>|null  $events
     * @return list<array<string, int|float|string>>
     */
    private function byChannel(Workspace $workspace, Period $period, Filters $filters, ?array $events): array
    {
        $rows = $this->messages($workspace, $period)->groupBy('m.channel')->selectRaw('m.channel AS channel, '.self::COUNTS)->get()->keyBy('channel');
        $sales = $this->attributed($workspace, $period, $filters)->groupBy('am.channel')
            ->selectRaw('am.channel AS channel, COUNT(*) AS orders, COALESCE(SUM(o.total_ws), 0) AS revenue')->get()->keyBy('channel');
        $out = [];
        foreach ($rows->keys()->merge($sales->keys())->unique() as $channel) {
            $channel = (string) $channel;
            $out[] = ['channel' => $channel] + $this->shape($rows->get($channel), $events === null ? null : self::sum($events, fn (array $e) => $e['channel'] === $channel), $sales->get($channel));
        }
        usort($out, fn (array $a, array $b) => [$b['revenue'], $b['sent']] <=> [$a['revenue'], $a['sent']]);

        return $out;
    }

    /**
     * Flujos, campañas, bandeja y otros envíos (API, avisos del sistema), con su nombre.
     *
     * @param  list<array{channel: string, flow: string, campaign: string, event: string, n: int}>|null  $events
     * @return list<array<string, mixed>>
     */
    private function bySource(Workspace $workspace, Period $period, Filters $filters, ?array $events): array
    {
        $key = "CASE WHEN %1\$s.flow_id IS NOT NULL THEN CONCAT('flow:', %1\$s.flow_id) WHEN %1\$s.campaign_id IS NOT NULL THEN CONCAT('campaign:', %1\$s.campaign_id)
            WHEN %1\$s.source_type = 'inbox' THEN 'inbox' ELSE 'other' END";
        $rows = $this->messages($workspace, $period)->groupByRaw('source_key')
            ->selectRaw(sprintf($key, 'm').' AS source_key, GROUP_CONCAT(DISTINCT m.channel ORDER BY m.channel) AS channels, '.self::COUNTS)->get()->keyBy('source_key');
        $sales = $this->attributed($workspace, $period, $filters)->groupByRaw('source_key')
            ->selectRaw(sprintf($key, 'am').' AS source_key, COUNT(*) AS orders, COALESCE(SUM(o.total_ws), 0) AS revenue')->get()->keyBy('source_key');

        $keys = $rows->keys()->merge($sales->keys())->unique()->map(fn ($k) => (string) $k)->values();
        $ids = fn (string $type) => $keys->filter(fn (string $k) => str_starts_with($k, $type.':'))->map(fn (string $k) => substr($k, strlen($type) + 1))->values()->all();
        $flowNames = DB::table('flows')->where('workspace_id', $workspace->id)->whereIn('id', $ids('flow'))->pluck('name', 'id')->all();
        $campaignNames = DB::table('campaigns')->where('workspace_id', $workspace->id)->whereIn('id', $ids('campaign'))->pluck('name', 'id')->all();

        $out = [];
        foreach ($keys as $k) {
            [$type, $id] = str_contains($k, ':') ? explode(':', $k, 2) : [$k, null];
            $match = match ($type) {
                'flow' => fn (array $e) => $e['flow'] === $id,
                'campaign' => fn (array $e) => $e['campaign'] === $id,
                default => fn (array $e) => false,
            };
            $row = $rows->get($k);
            $out[] = [
                'type' => $type,
                'id' => match ($type) {
                    'flow' => 'fl_'.$id,
                    'campaign' => 'cmp_'.$id,
                    default => null,
                },
                'name' => match ($type) {
                    'flow' => (string) ($flowNames[$id] ?? ''),
                    'campaign' => (string) ($campaignNames[$id] ?? ''),
                    default => null,
                },
                'channels' => $row !== null && is_string($row->channels) ? explode(',', $row->channels) : [],
            ] + $this->shape($row, $events === null || $id === null ? null : self::sum($events, $match), $sales->get($k));
        }
        usort($out, fn (array $a, array $b) => [$b['revenue'], $b['sent']] <=> [$a['revenue'], $a['sent']]);

        return array_slice($out, 0, 30);
    }

    /** @return list<array{date: string, sent: int, delivered: int, clicked: int}> */
    private function series(Workspace $workspace, Period $period): array
    {
        $offset = $period->offsetSeconds();
        $bucket = match ($period->granularity) {
            'week' => 'DATE(DATE_SUB(DATE_ADD(m.created_at, INTERVAL ? SECOND), INTERVAL WEEKDAY(DATE_ADD(m.created_at, INTERVAL ? SECOND)) DAY))',
            'month' => "DATE_FORMAT(DATE_ADD(m.created_at, INTERVAL ? SECOND), '%Y-%m-01')",
            default => 'DATE(DATE_ADD(m.created_at, INTERVAL ? SECOND))',
        };
        $rows = $this->messages($workspace, $period)->groupByRaw('d')
            ->selectRaw("{$bucket} AS d, ".self::COUNTS, array_fill(0, substr_count($bucket, '?'), $offset))
            ->get()->keyBy('d');
        $out = [];
        foreach ($period->buckets() as $b) {
            $r = $rows->get($b);
            $out[] = ['date' => $b, 'sent' => (int) ($r->sent ?? 0), 'delivered' => (int) ($r->delivered ?? 0), 'clicked' => (int) ($r->clicked ?? 0)];
        }

        return $out;
    }

    /**
     * Aperturas reales (sin Apple MPP), bajas y quejas del periodo en ClickHouse, por canal, flujo y campaña.
     *
     * @return list<array{channel: string, flow: string, campaign: string, event: string, n: int}>|null null sin ClickHouse
     */
    private function events(Workspace $workspace, Period $period): ?array
    {
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT toString(properties.channel) AS channel, toString(properties.flow_id) AS flow, toString(properties.campaign_id) AS campaign,
                        event, uniqExact(toString(properties.message_id)) AS n
                 FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND event IN ('message.opened', 'message.unsubscribed', 'message.complained')
                   AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}
                   AND NOT (event = 'message.opened' AND toString(properties.is_mpp) = 'true')
                 GROUP BY channel, flow, campaign, event",
                self::chRange($period),
            );
        } catch (Throwable) {
            return null;
        }

        return array_map(fn (array $r) => [
            'channel' => (string) $r['channel'], 'flow' => (string) $r['flow'], 'campaign' => (string) $r['campaign'], 'event' => (string) $r['event'], 'n' => (int) $r['n'],
        ], $rows);
    }

    /**
     * @param  list<array{channel: string, flow: string, campaign: string, event: string, n: int}>  $events
     * @param  callable(array{channel: string, flow: string, campaign: string, event: string, n: int}): bool  $match
     * @return array{opened: int, unsubscribed: int, complained: int}
     */
    private static function sum(array $events, callable $match): array
    {
        $opened = $unsubscribed = $complained = 0;
        foreach ($events as $e) {
            if (! $match($e)) {
                continue;
            }
            match ($e['event']) {
                'message.opened' => $opened += $e['n'],
                'message.unsubscribed' => $unsubscribed += $e['n'],
                default => $complained += $e['n'],
            };
        }

        return ['opened' => $opened, 'unsubscribed' => $unsubscribed, 'complained' => $complained];
    }

    /**
     * @param  array{opened: int, unsubscribed: int, complained: int}|null  $events
     * @return array{total: int, sent: int, delivered: int, read: int, opened: int, clicked: int, replied: int, unsubscribed: int,
     *     complained: int, bounced: int, failed: int, orders: int, revenue: float}
     */
    private function shape(?object $row, ?array $events, ?object $sales): array
    {
        $int = fn (string $k) => (int) ($row->{$k} ?? 0);

        return [
            'total' => $int('total'),
            'sent' => $int('sent'),
            'delivered' => $int('delivered'),
            'read' => $int('read_count'),
            // Sin ClickHouse, las aperturas de la columna (incluyen las automáticas de Apple).
            'opened' => $events !== null ? $events['opened'] : $int('opened'),
            'clicked' => $int('clicked'),
            'replied' => $int('replied'),
            'unsubscribed' => $events['unsubscribed'] ?? 0,
            'complained' => $events['complained'] ?? 0,
            'bounced' => $int('bounced'),
            'failed' => $int('failed'),
            'orders' => (int) ($sales->orders ?? 0),
            'revenue' => round((float) ($sales->revenue ?? 0), 2),
        ];
    }
}
