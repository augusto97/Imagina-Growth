<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #6 Productos estrella (20 % que más unidades vendió en 30 días) con menos
 * de 14 días de inventario al ritmo actual.
 */
final class LowStockStarsDetector implements Detector
{
    public function key(): string
    {
        return 'low_stock_stars';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(30);
        $sold = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', $since->utc())
            ->groupBy('i.external_product_id')
            ->selectRaw('i.external_product_id AS pid, SUM(i.qty) AS units')
            ->orderByDesc('units')
            ->get();
        if ($sold->isEmpty()) {
            return [];
        }
        $starCount = max(1, (int) ceil($sold->count() * 0.2));
        $stars = $sold->take($starCount)->filter(fn ($r) => (float) $r->units >= 3)->keyBy('pid');
        if ($stars->isEmpty()) {
            return [];
        }
        $products = DB::table('products')->where('workspace_id', $workspace->id)->whereIn('external_id', $stars->keys())
            ->whereNull('deleted_at')->whereNotNull('stock_qty')->get(['external_id', 'name', 'stock_qty', 'price']);

        $low = [];
        $impact = 0.0;
        foreach ($products as $p) {
            $daily = (float) $stars[$p->external_id]->units / 30;
            $days = $daily > 0 ? (float) $p->stock_qty / $daily : INF;
            if ($days >= 14) {
                continue;
            }
            $missing = max(0.0, 30 - $days) * $daily * (float) $p->price;
            $impact += $missing;
            $low[] = ['product_id' => (string) $p->external_id, 'name' => (string) $p->name, 'stock' => (float) $p->stock_qty,
                'units_30d' => (float) $stars[$p->external_id]->units, 'days_left' => round($days, 1), 'revenue_at_risk' => round($missing, 2)];
        }
        if ($low === []) {
            return [];
        }
        usort($low, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);
        $first = $low[0];

        return [new InsightCandidate(
            fingerprint: 'stars',
            severity: $first['days_left'] < 7 ? 'warning' : 'opportunity',
            title: count($low) === 1
                ? __(':name, uno de tus más vendidos, se agota en :days días', ['name' => $first['name'], 'days' => number_format($first['days_left'], 0)])
                : __(':count de tus productos más vendidos se agotan en menos de 14 días', ['count' => count($low)]),
            facts: ['products' => array_slice($low, 0, 10), 'window_days' => 30, 'threshold_days' => 14],
            impact: (string) round($impact, 2),
            assumptions: [
                __('Se siguen vendiendo al ritmo de los últimos 30 días.'),
                __('Las ventas en riesgo son los días sin inventario dentro de los próximos 30, por el precio actual.'),
            ],
            action: ['label' => __('Revisa el inventario de estos productos'), 'to' => 'analitica/productos'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
        )];
    }
}
