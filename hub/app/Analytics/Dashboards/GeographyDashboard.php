<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;

/** 7. Geografía: ventas por departamento o estado y ciudad, y conversión de las visitas por ciudad. */
final class GeographyDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $regions = $this->paidOrders($workspace, $period, $filters)
            ->groupByRaw("COALESCE(shipping_region_code, '')")
            ->selectRaw("COALESCE(shipping_region_code, '') AS region, COUNT(*) AS orders, COALESCE(SUM(total_ws), 0) AS revenue")
            ->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['region' => (string) $r->region, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2), 'aov' => round(self::ratio((float) $r->revenue, (int) $r->orders, 2), 2)])->all();

        $sales = $this->paidOrders($workspace, $period, $filters)
            ->groupByRaw("COALESCE(shipping_city, ''), COALESCE(shipping_region_code, '')")
            ->selectRaw("COALESCE(shipping_city, '') AS city, COALESCE(shipping_region_code, '') AS region, COUNT(*) AS orders, COALESCE(SUM(total_ws), 0) AS revenue")
            ->get();
        [$fsql, $params] = $filters->sessionsSql();
        $visits = Ch::forWorkspace($workspace)->select(
            "SELECT city, count() AS sessions, sum(purchased) AS purchases FROM sessions FINAL
             WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from:DateTime64(3)} AND started_at < {to:DateTime64(3)} AND city != ''{$fsql}
             GROUP BY city",
            $params + self::chRange($period),
        );
        $visitBy = [];
        foreach ($visits as $v) {
            $visitBy[mb_strtolower((string) $v['city'])] = $v;
        }
        $cities = [];
        foreach ($sales as $s) {
            $v = $visitBy[mb_strtolower((string) $s->city)] ?? null;
            $cities[] = [
                'city' => (string) $s->city, 'region' => (string) $s->region, 'orders' => (int) $s->orders, 'revenue' => round((float) $s->revenue, 2),
                'sessions' => $v !== null ? (int) $v['sessions'] : null,
                'conversion' => $v !== null ? self::ratio((int) $v['purchases'], (int) $v['sessions']) : null,
            ];
        }
        usort($cities, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return ['regions' => $regions, 'cities' => array_slice($cities, 0, 50)];
    }
}
