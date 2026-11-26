<?php

declare(strict_types=1);

namespace App\Domain\Services\Queries;

use App\Analytics\Semantic\Period;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Flows\Models\Flow;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Queries\LeadKpis;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Facades\DB;

/**
 * Resultados de un negocio sin tienda (rediseño v2 §11.2, modulos.md M18 «Resultados de servicios») para un periodo:
 *
 * - `kpis`: los del Inicio (ServicesKpis) frente al periodo anterior.
 * - `leads`: de LeadKpis (el embudo del pipeline principal, leads por fuente con sus ganados y su valor, primera
 *   respuesta) y los leads por origen y por día o semana.
 * - `appointments`: agendadas en el periodo; de las citas del periodo (por su hora), cumplidas, no asistidas,
 *   canceladas y pendientes, la tasa de asistencia (cumplidas ÷ cumplidas + no asistidas) y las próximas desde hoy.
 * - `quotes`: cotizaciones enviadas en el periodo (las del cotizador con contacto y las propuestas: un lead que entró a
 *   una etapa de cotización) y cuántas se aceptaron (su lead se ganó), con el valor.
 * - `attribution`: los leads ganados del periodo con su valor por lo que los trajo, una sola vez cada uno en este
 *   orden: un mensaje de un flujo o de una campaña con clic o respuesta en los 5 días antes de ganarse (la regla de
 *   los pedidos, hub/CLAUDE.md §9), y si no, su origen (formulario, chat, agenda o cotizador); los manuales y de la API
 *   cuentan como «otros». Con el detalle por flujo y campaña.
 *
 * Todo sale de MariaDB y siempre filtrado por `workspace_id`.
 */
final class ServicesResults
{
    /** Ventana de atribución de un mensaje con clic o respuesta (días). */
    public const ATTRIBUTION_DAYS = 5;

    /** Origen del lead → grupo de la atribución cuando ningún mensaje lo explica. */
    private const ORIGIN_GROUPS = ['form' => 'forms', 'whatsapp' => 'chats', 'messenger' => 'chats', 'instagram' => 'chats', 'booking' => 'bookings', 'quote' => 'quotes'];

    public const ATTRIBUTION_GROUPS = ['flows', 'campaigns', 'forms', 'chats', 'bookings', 'quotes', 'other'];

    public function __construct(private readonly LeadKpis $leadKpis) {}

    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, Period $period): array
    {
        $ws = $workspace->id;
        $now = ServicesKpis::measure($ws, $period);
        $before = $period->compare !== null ? ServicesKpis::measure($ws, $period->compare) : [];
        $kpis = [];
        foreach ($now as $name => $value) {
            $previous = $before[$name] ?? null;
            $kpis[$name] = ['value' => $value, 'previous' => $previous, 'change_pct' => $value !== null && $previous !== null && $previous > 0 ? round(($value - $previous) / $previous * 100, 1) : null];
        }
        $pipeline = Pipelines::default($ws);
        $leads = $this->leadKpis->handle($ws, $pipeline, $period->from, $period->to);

        return [
            'period' => $period->toArray(),
            'currency' => (string) ($workspace->currency ?: 'COP'),
            'units' => ServicesKpis::UNITS,
            'kpis' => $kpis,
            'leads' => [
                'total' => $leads['leads'],
                'conversion_rate' => $leads['conversion_rate'],
                'open' => $leads['open'],
                'first_response' => $leads['first_response'],
                'by_source' => $leads['by_source'],
                'by_origin' => ServicesKpis::origins($ws, $period),
                'series' => $this->series($ws, $period),
                'funnel' => ['pipeline' => ['id' => $pipeline->publicId(), 'name' => $pipeline->name], 'stages' => $leads['funnel']],
            ],
            'appointments' => $this->appointments($ws, $period),
            'quotes' => $this->quotes($ws, $period, $pipeline),
            'won' => $leads['won'],
            'attribution' => $this->attribution($ws, $period),
        ];
    }

    /** @return list<array{date: string, leads: int, won: int, won_value: string}> por día, semana o mes según el periodo */
    private function series(string $ws, Period $period): array
    {
        $offset = (int) $period->offsetSeconds();
        // El inicio de cada día, semana (lunes) o mes en la zona del negocio, como Period::buckets().
        [$created, $won, $marks] = match ($period->granularity) {
            'week' => [
                'DATE_SUB(DATE(DATE_ADD(created_at, INTERVAL ? SECOND)), INTERVAL WEEKDAY(DATE_ADD(created_at, INTERVAL ? SECOND)) DAY) AS d, COUNT(*) AS n',
                'DATE_SUB(DATE(DATE_ADD(won_at, INTERVAL ? SECOND)), INTERVAL WEEKDAY(DATE_ADD(won_at, INTERVAL ? SECOND)) DAY) AS d, COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v',
                2,
            ],
            'month' => [
                "DATE_FORMAT(DATE_ADD(created_at, INTERVAL ? SECOND), '%Y-%m-01') AS d, COUNT(*) AS n",
                "DATE_FORMAT(DATE_ADD(won_at, INTERVAL ? SECOND), '%Y-%m-01') AS d, COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v",
                1,
            ],
            default => [
                'DATE(DATE_ADD(created_at, INTERVAL ? SECOND)) AS d, COUNT(*) AS n',
                'DATE(DATE_ADD(won_at, INTERVAL ? SECOND)) AS d, COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v',
                1,
            ],
        };
        $bindings = array_fill(0, $marks, $offset);
        $range = [$period->from->utc(), $period->to->utc()];
        $rows = [];
        foreach (DB::table('leads')->where('workspace_id', $ws)->whereBetween('created_at', $range)->selectRaw($created, $bindings)->groupBy('d')->get() as $r) {
            $rows[substr((string) $r->d, 0, 10)]['leads'] = (int) $r->n;
        }
        foreach (DB::table('leads')->where('workspace_id', $ws)->whereBetween('won_at', $range)->selectRaw($won, $bindings)->groupBy('d')->get() as $r) {
            $rows[substr((string) $r->d, 0, 10)]['won'] = (int) $r->n;
            $rows[substr((string) $r->d, 0, 10)]['won_value'] = (float) $r->v;
        }
        $out = [];
        foreach ($period->buckets() as $date) {
            $out[] = ['date' => $date, 'leads' => (int) ($rows[$date]['leads'] ?? 0), 'won' => (int) ($rows[$date]['won'] ?? 0),
                'won_value' => number_format((float) ($rows[$date]['won_value'] ?? 0), 2, '.', '')];
        }

        return $out;
    }

    /** @return array<string, int|float|null> */
    private function appointments(string $ws, Period $period): array
    {
        $row = DB::table('appointments')->where('workspace_id', $ws)->whereBetween('starts_at', [$period->from->utc(), $period->to->utc()])
            ->selectRaw("SUM(status = 'completed') AS completed, SUM(status = 'no_show') AS no_show, SUM(status = 'cancelled') AS cancelled,
                SUM(status IN ('booked', 'confirmed', 'rescheduled')) AS pending, SUM(status = 'confirmed') AS confirmed")->first();
        $completed = (int) ($row->completed ?? 0);
        $noShow = (int) ($row->no_show ?? 0);
        $booked = (int) DB::table('appointments')->where('workspace_id', $ws)->whereBetween('created_at', [$period->from->utc(), $period->to->utc()])->count();

        return [
            'booked' => $booked,
            'completed' => $completed,
            'no_show' => $noShow,
            'cancelled' => (int) ($row->cancelled ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'confirmed' => (int) ($row->confirmed ?? 0),
            'upcoming' => (int) DB::table('appointments')->where('workspace_id', $ws)->whereIn('status', ['booked', 'confirmed', 'rescheduled'])->where('starts_at', '>=', now())->count(),
            'attendance_rate' => $completed + $noShow > 0 ? round($completed / ($completed + $noShow), 4) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function quotes(string $ws, Period $period, LeadPipeline $pipeline): array
    {
        $range = [$period->from->utc(), $period->to->utc()];
        $calculator = DB::table('quotes')->where('workspace_id', $ws)->whereNotNull('contact_id')->whereBetween('created_at', $range);
        $calculatorCount = (int) (clone $calculator)->count();
        $stages = array_values(array_intersect(LeadDefaults::QUOTE_STAGES, array_column($pipeline->stages, 'key'))) ?: LeadDefaults::QUOTE_STAGES;
        $proposals = DB::table('lead_activities as la')->join('leads as l', 'l.id', '=', 'la.lead_id')
            ->where('l.workspace_id', $ws)->where('la.workspace_id', $ws)->where('la.kind', 'stage')->whereBetween('la.created_at', $range)
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(la.data, '$.to'))"), LeadDefaults::QUOTE_STAGES)
            ->distinct()->pluck('la.lead_id')->map(fn ($v) => (string) $v)->all();
        $leadIds = array_values(array_unique([...array_map('strval', (clone $calculator)->whereNotNull('lead_id')->pluck('lead_id')->all()), ...$proposals]));
        $accepted = $leadIds === [] ? null : DB::table('leads')->where('workspace_id', $ws)->whereIn('id', $leadIds)->whereNotNull('won_at')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v')->first();
        $sent = $calculatorCount + count($proposals);
        $acceptedCount = (int) ($accepted->n ?? 0);

        return [
            'sent' => $sent,
            'from_calculator' => $calculatorCount,
            'proposals' => count($proposals),
            'accepted' => $acceptedCount,
            'accepted_value' => number_format((float) ($accepted->v ?? 0), 2, '.', ''),
            'acceptance_rate' => $leadIds !== [] ? round($acceptedCount / count($leadIds), 4) : null,
            'stages' => $stages,
        ];
    }

    /** @return array<string, mixed> */
    private function attribution(string $ws, Period $period): array
    {
        $days = self::ATTRIBUTION_DAYS;
        $touch = "SELECT CONCAT(m.source_type, ':', COALESCE(m.flow_id, m.campaign_id, '')) FROM messages m
            WHERE m.workspace_id = l.workspace_id AND m.contact_id = l.contact_id AND m.direction = 'out' AND m.source_type IN ('flow', 'campaign')
              AND COALESCE(m.clicked_at, m.replied_at) IS NOT NULL
              AND COALESCE(m.clicked_at, m.replied_at) BETWEEN DATE_SUB(l.won_at, INTERVAL {$days} DAY) AND l.won_at
            ORDER BY COALESCE(m.clicked_at, m.replied_at) DESC LIMIT 1";
        $rows = DB::table('leads as l')->where('l.workspace_id', $ws)->whereBetween('l.won_at', [$period->from->utc(), $period->to->utc()])
            ->selectRaw("l.origin, l.value_ws, ({$touch}) AS touch")->limit(20000)->get();
        $groups = array_fill_keys(self::ATTRIBUTION_GROUPS, ['count' => 0, 'value' => 0.0]);
        $byFlow = [];
        $byCampaign = [];
        foreach ($rows as $r) {
            $value = (float) ($r->value_ws ?? 0);
            $touch = is_string($r->touch) ? explode(':', $r->touch, 2) : null;
            $group = match (true) {
                $touch !== null && $touch[0] === 'flow' => 'flows',
                $touch !== null && $touch[0] === 'campaign' => 'campaigns',
                default => self::ORIGIN_GROUPS[(string) $r->origin] ?? 'other',
            };
            $groups[$group]['count']++;
            $groups[$group]['value'] += $value;
            $id = $touch[1] ?? '';
            if ($group === 'flows' && $id !== '') {
                $byFlow[$id] = ['count' => ($byFlow[$id]['count'] ?? 0) + 1, 'value' => ($byFlow[$id]['value'] ?? 0.0) + $value];
            } elseif ($group === 'campaigns' && $id !== '') {
                $byCampaign[$id] = ['count' => ($byCampaign[$id]['count'] ?? 0) + 1, 'value' => ($byCampaign[$id]['value'] ?? 0.0) + $value];
            }
        }
        $platform = $groups['flows']['value'] + $groups['campaigns']['value'] + $groups['forms']['value'] + $groups['chats']['value'] + $groups['bookings']['value'] + $groups['quotes']['value'];

        return [
            'window_days' => $days,
            'won' => $rows->count(),
            'platform_value' => number_format($platform, 2, '.', ''),
            'groups' => array_map(fn (string $key) => ['key' => $key, 'label' => (string) __('services.attribution.'.$key), 'count' => $groups[$key]['count'],
                'value' => number_format($groups[$key]['value'], 2, '.', '')], self::ATTRIBUTION_GROUPS),
            'flows' => $this->named($ws, 'flows', Flow::ID_PREFIX, $byFlow),
            'campaigns' => $this->named($ws, 'campaigns', Campaign::ID_PREFIX, $byCampaign),
        ];
    }

    /**
     * @param  array<string, array{count: int, value: float}>  $items
     * @return list<array{id: string, name: string, count: int, value: string}>
     */
    private function named(string $ws, string $table, string $prefix, array $items): array
    {
        if ($items === []) {
            return [];
        }
        $names = DB::table($table)->where('workspace_id', $ws)->whereIn('id', array_keys($items))->pluck('name', 'id');
        uasort($items, fn (array $a, array $b) => $b['value'] <=> $a['value']);
        $out = [];
        foreach ($items as $id => $item) {
            $out[] = ['id' => PrefixedId::encode($prefix, (string) $id), 'name' => (string) ($names[$id] ?? ''), 'count' => $item['count'], 'value' => number_format($item['value'], 2, '.', '')];
        }

        return $out;
    }
}
