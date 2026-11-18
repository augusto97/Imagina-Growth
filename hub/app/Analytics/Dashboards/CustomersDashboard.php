<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Rfm\RfmSegment;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 4. Clientes: mapa RFM (clic → segmento), cohortes de retención por mes de
 * primera compra, tasa de recompra, tiempo hasta la 2.ª compra y concentración
 * de ingresos (inteligencia.md §1 y §3).
 */
final class CustomersDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $ws = $workspace->id;
        $rfm = DB::table('contacts')->where('workspace_id', $ws)->whereNotNull('rfm_segment')->whereNull('deleted_at')
            ->groupBy('rfm_segment')
            ->selectRaw('rfm_segment AS segment, COUNT(*) AS customers, COALESCE(SUM(revenue_total_ws), 0) AS revenue, AVG(DATEDIFF(NOW(), last_order_at)) AS recency_days, AVG(orders_count) AS orders')
            ->get()->keyBy('segment');
        $totalRevenue = (float) $rfm->sum('revenue');
        $segments = [];
        foreach (RfmSegment::cases() as $s) {
            $r = $rfm->get($s->value);
            $segments[] = [
                'segment' => $s->value,
                'customers' => (int) ($r->customers ?? 0),
                'revenue' => round((float) ($r->revenue ?? 0), 2),
                'revenue_share' => self::ratio((float) ($r->revenue ?? 0), $totalRevenue),
                'recency_days' => $r !== null ? (int) round((float) $r->recency_days) : null,
                'avg_orders' => $r !== null ? round((float) $r->orders, 2) : null,
                'system_segment' => 'rfm_'.$s->value,
            ];
        }
        $grid = DB::table('contacts')->where('workspace_id', $ws)->whereNotNull('rfm_r')->whereNull('deleted_at')
            ->groupBy('rfm_r', 'rfm_f')->selectRaw('rfm_r AS r, rfm_f AS f, COUNT(*) AS n')->get()
            ->map(fn ($g) => ['r' => (int) $g->r, 'f' => (int) $g->f, 'customers' => (int) $g->n])->all();
        $computedAt = DB::table('contacts')->where('workspace_id', $ws)->max('rfm_computed_at');

        return [
            'rfm' => ['segments' => $segments, 'grid' => $grid, 'computed_at' => $computedAt],
            'cohorts' => $this->cohorts($workspace, $period),
            'repurchase' => $this->repurchase($workspace),
            'concentration' => $this->concentration($workspace),
            'new_vs_returning' => $this->newVsReturning($workspace, $period, $filters),
        ];
    }

    /**
     * Retención mensual: de los clientes cuya primera compra fue en el mes X,
     * qué parte volvió a comprar k meses después.
     *
     * @return list<array<string, mixed>>
     */
    private function cohorts(Workspace $workspace, Period $period): array
    {
        $offset = $period->offsetSeconds();
        $start = CarbonImmutable::now($period->timezone)->startOfMonth()->subMonths(11);
        $rows = DB::table('orders as o')
            ->join('orders as f', function ($j): void {
                $j->on('f.contact_id', '=', 'o.contact_id')->where('f.is_first_order', true);
            })
            ->where('o.workspace_id', $workspace->id)->where('f.workspace_id', $workspace->id)->where('o.is_paid', true)
            ->where('f.created_at', '>=', $start->utc())
            ->groupByRaw('cohort, k')
            ->selectRaw("DATE_FORMAT(DATE_ADD(f.created_at, INTERVAL ? SECOND), '%Y-%m') AS cohort,
                PERIOD_DIFF(DATE_FORMAT(DATE_ADD(o.created_at, INTERVAL ? SECOND), '%Y%m'), DATE_FORMAT(DATE_ADD(f.created_at, INTERVAL ? SECOND), '%Y%m')) AS k,
                COUNT(DISTINCT o.contact_id) AS customers", [$offset, $offset, $offset])
            ->get();
        $table = [];
        foreach ($rows as $r) {
            $table[(string) $r->cohort][(int) $r->k] = (int) $r->customers;
        }
        ksort($table);
        $out = [];
        foreach ($table as $cohort => $ks) {
            $size = $ks[0] ?? 0;
            $retention = [];
            for ($k = 1; $k <= 11; $k++) {
                $retention[] = isset($ks[$k]) || $k <= $this->monthsSince($cohort, $period->timezone) ? self::ratio($ks[$k] ?? 0, $size) : null;
            }
            $out[] = ['cohort' => $cohort, 'customers' => $size, 'retention' => $retention];
        }

        return $out;
    }

    private function monthsSince(string $cohort, string $tz): int
    {
        $c = CarbonImmutable::parse($cohort.'-01', $tz);

        return (int) $c->diffInMonths(CarbonImmutable::now($tz)->startOfMonth());
    }

    /** @return array<string, mixed> */
    private function repurchase(Workspace $workspace): array
    {
        $since = CarbonImmutable::now()->subMonths(12);
        $row = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->whereNotNull('contact_id')
            ->where('created_at', '>=', $since)->groupBy('contact_id')->selectRaw('contact_id, COUNT(*) AS n');
        $agg = DB::query()->fromSub($row, 't')->selectRaw('COUNT(*) AS buyers, SUM(n >= 2) AS repeat_buyers')->first();

        $gaps = DB::table('orders as a')
            ->join('orders as b', function ($j): void {
                $j->on('b.contact_id', '=', 'a.contact_id')->where('b.order_index', 2);
            })
            ->where('a.workspace_id', $workspace->id)->where('b.workspace_id', $workspace->id)->where('a.order_index', 1)
            ->selectRaw('DATEDIFF(b.created_at, a.created_at) AS d')->orderBy('d')->pluck('d')->map(fn ($v) => (int) $v)->all();
        $median = $gaps === [] ? null : $gaps[intdiv(count($gaps), 2)];

        return [
            'window_months' => 12,
            'buyers' => (int) ($agg->buyers ?? 0),
            'repeat_buyers' => (int) ($agg->repeat_buyers ?? 0),
            'rate' => self::ratio((int) ($agg->repeat_buyers ?? 0), (int) ($agg->buyers ?? 0)),
            'median_days_to_second' => $median,
            'second_purchases' => count($gaps),
        ];
    }

    /** @return array<string, mixed> */
    private function concentration(Workspace $workspace): array
    {
        $revenues = DB::table('contacts')->where('workspace_id', $workspace->id)->where('orders_count', '>', 0)->whereNull('deleted_at')
            ->orderByDesc('revenue_total_ws')->pluck('revenue_total_ws')->map(fn ($v) => (float) $v)->all();
        $n = count($revenues);
        $total = array_sum($revenues);
        $share = fn (float $pct) => $n === 0 ? 0.0 : self::ratio(array_sum(array_slice($revenues, 0, max(1, (int) ceil($n * $pct)))), $total);

        return ['customers' => $n, 'top10_share' => $share(0.1), 'top20_share' => $share(0.2), 'total_revenue' => round($total, 2)];
    }

    /** @return list<array<string, mixed>> */
    private function newVsReturning(Workspace $workspace, Period $period, Filters $filters): array
    {
        $offset = $period->offsetSeconds();
        // El desfase va como parámetro (cada «?» es la zona en segundos).
        $bucket = match ($period->granularity) {
            'week' => 'DATE(DATE_SUB(DATE_ADD(created_at, INTERVAL ? SECOND), INTERVAL WEEKDAY(DATE_ADD(created_at, INTERVAL ? SECOND)) DAY))',
            'month' => "DATE_FORMAT(DATE_ADD(created_at, INTERVAL ? SECOND), '%Y-%m-01')",
            default => 'DATE(DATE_ADD(created_at, INTERVAL ? SECOND))',
        };
        $rows = $this->paidOrders($workspace, $period, $filters)->groupByRaw('d')
            ->selectRaw("{$bucket} AS d, SUM(is_first_order = 1) AS new_orders, SUM(order_index > 1) AS returning_orders,
                COALESCE(SUM(CASE WHEN is_first_order = 1 THEN total_ws END), 0) AS new_revenue, COALESCE(SUM(CASE WHEN order_index > 1 THEN total_ws END), 0) AS returning_revenue", array_fill(0, substr_count($bucket, '?'), $offset))
            ->get()->keyBy('d');
        $out = [];
        foreach ($period->buckets() as $b) {
            $r = $rows->get($b);
            $out[] = ['date' => $b, 'new_orders' => (int) ($r->new_orders ?? 0), 'returning_orders' => (int) ($r->returning_orders ?? 0),
                'new_revenue' => round((float) ($r->new_revenue ?? 0), 2), 'returning_revenue' => round((float) ($r->returning_revenue ?? 0), 2)];
        }

        return $out;
    }
}
