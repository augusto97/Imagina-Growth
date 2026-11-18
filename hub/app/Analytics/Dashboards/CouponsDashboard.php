<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 8. Cupones y descuentos: uso, costo y dependencia de descuentos
 * (inteligencia.md §4.6). El ingreso incremental real se mide con grupos de
 * control (Fase 2); aquí se muestra el costo y la tendencia.
 */
final class CouponsDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $totals = $this->paidOrders($workspace, $period, $filters)
            ->selectRaw("COUNT(*) AS orders, SUM(JSON_LENGTH(COALESCE(coupon_codes, '[]')) > 0) AS with_coupon,
                COALESCE(SUM(discount), 0) AS discount, COALESCE(SUM(CASE WHEN JSON_LENGTH(COALESCE(coupon_codes, '[]')) > 0 THEN total_ws END), 0) AS revenue_with_coupon,
                COALESCE(AVG(CASE WHEN JSON_LENGTH(COALESCE(coupon_codes, '[]')) > 0 THEN total_ws END), 0) AS aov_with,
                COALESCE(AVG(CASE WHEN JSON_LENGTH(COALESCE(coupon_codes, '[]')) = 0 THEN total_ws END), 0) AS aov_without")
            ->first();

        $codes = $this->paidOrders($workspace, $period, $filters, 'o')
            ->join(DB::raw("JSON_TABLE(o.coupon_codes, '$[*]' COLUMNS (code VARCHAR(100) PATH '$')) AS jt"), DB::raw('1'), '=', DB::raw('1'))
            ->groupBy('jt.code')
            ->selectRaw('UPPER(jt.code) AS code, COUNT(*) AS orders, COALESCE(SUM(o.discount), 0) AS discount, COALESCE(SUM(o.total_ws), 0) AS revenue')
            ->orderByDesc('orders')->limit(20)->get()
            ->map(fn ($r) => ['code' => (string) $r->code, 'orders' => (int) $r->orders, 'discount' => round((float) $r->discount, 2), 'revenue' => round((float) $r->revenue, 2)])->all();

        $start = CarbonImmutable::now($period->timezone)->startOfMonth()->subMonths(11);
        $offset = $period->offsetSeconds();
        $trend = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->where('created_at', '>=', $start->utc())
            ->groupByRaw('m')
            ->selectRaw("DATE_FORMAT(DATE_ADD(created_at, INTERVAL {$offset} SECOND), '%Y-%m') AS m, COUNT(*) AS orders,
                SUM(JSON_LENGTH(COALESCE(coupon_codes, '[]')) > 0) AS with_coupon, COALESCE(SUM(discount), 0) AS discount")
            ->orderBy('m')->get()
            ->map(fn ($r) => ['month' => (string) $r->m, 'orders' => (int) $r->orders, 'coupon_share' => self::ratio((int) $r->with_coupon, (int) $r->orders), 'discount' => round((float) $r->discount, 2)])->all();

        $dependency = DB::table('contacts')->where('workspace_id', $workspace->id)->where('orders_count', '>=', 2)->whereNull('deleted_at')
            ->selectRaw('SUM(coupon_orders_ratio >= 0.8) AS only_discount, SUM(coupon_orders_ratio = 0) AS never, COUNT(*) AS repeat_customers')->first();

        $orders = (int) ($totals->orders ?? 0);

        return [
            'orders' => $orders,
            'orders_with_coupon' => (int) ($totals->with_coupon ?? 0),
            'coupon_share' => self::ratio((int) ($totals->with_coupon ?? 0), $orders),
            'discount' => round((float) ($totals->discount ?? 0), 2),
            'revenue_with_coupon' => round((float) ($totals->revenue_with_coupon ?? 0), 2),
            'aov_with_coupon' => round((float) ($totals->aov_with ?? 0), 2),
            'aov_without_coupon' => round((float) ($totals->aov_without ?? 0), 2),
            'codes' => $codes,
            'trend' => $trend,
            'dependency' => [
                'repeat_customers' => (int) ($dependency->repeat_customers ?? 0),
                'only_with_discount' => (int) ($dependency->only_discount ?? 0),
                'never_use_coupons' => (int) ($dependency->never ?? 0),
            ],
            'incremental' => ['available' => false, 'phase' => 2],
        ];
    }
}
