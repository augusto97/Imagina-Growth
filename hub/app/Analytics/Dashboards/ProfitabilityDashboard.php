<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * 10. Rentabilidad (si hay costos): margen bruto por producto y pedido, MER
 * (ventas ÷ gasto total en anuncios) y ROAS por plataforma con el gasto importado.
 */
final class ProfitabilityDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $withCost = (clone $this->paidOrders($workspace, $period, $filters))->whereNotNull('cost_ws')->count();
        $orders = (clone $this->paidOrders($workspace, $period, $filters))->count();
        if ($withCost === 0) {
            return ['available' => false, 'reason' => 'no_costs', 'orders' => $orders];
        }
        $totals = $this->paidOrders($workspace, $period, $filters)->whereNotNull('cost_ws')
            ->selectRaw('COALESCE(SUM(total_ws), 0) AS revenue, COALESCE(SUM(cost_ws), 0) AS cost, COALESCE(SUM(shipping), 0) AS shipping, COALESCE(SUM(tax), 0) AS tax, COUNT(*) AS n')
            ->first();
        $revenue = (float) $totals->revenue;
        $cost = (float) $totals->cost;
        $netSales = $revenue - (float) $totals->shipping - (float) $totals->tax;

        $products = $this->paidOrders($workspace, $period, $filters, 'o')->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->whereNotNull('i.cost')
            ->groupBy('i.external_product_id')
            ->selectRaw('i.external_product_id AS pid, MAX(i.name) AS name, SUM(i.qty) AS units, SUM(i.line_total) AS revenue, SUM(i.cost * i.qty) AS cost')
            ->get()
            ->map(fn ($r) => ['product_id' => (string) $r->pid, 'name' => (string) $r->name, 'units' => round((float) $r->units, 2),
                'revenue' => round((float) $r->revenue, 2), 'cost' => round((float) $r->cost, 2), 'margin' => round((float) $r->revenue - (float) $r->cost, 2),
                'margin_rate' => self::ratio((float) $r->revenue - (float) $r->cost, (float) $r->revenue)])
            ->sortByDesc('margin')->values()->take(50)->all();

        $spend = DB::table('ad_spend_daily')->where('workspace_id', $workspace->id)
            ->where('date', '>=', $period->from->toDateString())->where('date', '<', $period->to->toDateString())
            ->groupBy('platform')->selectRaw('platform, SUM(spend) AS spend')->pluck('spend', 'platform')->map(fn ($v) => (float) $v)->all();
        $totalSpend = array_sum($spend);
        $allRevenue = (float) $this->paidOrders($workspace, $period, $filters)->sum('total_ws');

        return [
            'available' => true,
            'coverage' => self::ratio($withCost, $orders),
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'gross_margin' => round($netSales - $cost, 2),
            'gross_margin_rate' => self::ratio($netSales - $cost, $netSales),
            'avg_margin_per_order' => round(self::ratio($netSales - $cost, (int) $totals->n, 2), 2),
            'products' => $products,
            'ad_spend' => $spend,
            'mer' => $totalSpend > 0 ? round($allRevenue / $totalSpend, 2) : null,
            'roas_by_platform' => $totalSpend > 0 ? $this->roas($workspace, $period, $filters, $spend) : [],
        ];
    }

    /**
     * @param  array<string, float>  $spend
     * @return array<string, float|null>
     */
    private function roas(Workspace $workspace, Period $period, Filters $filters, array $spend): array
    {
        $orders = $this->paidOrders($workspace, $period, $filters)->selectRaw('contact_id, UNIX_TIMESTAMP(created_at) AS ts, total_ws, channel_last')->get();
        $models = (new Attribution)->load($workspace, $period->from, $period->to)->attribute($orders);
        $map = ['meta' => 'paid_meta', 'google' => 'paid_google', 'tiktok' => 'paid_tiktok'];
        $out = [];
        foreach ($spend as $platform => $amount) {
            $revenue = (float) ($models['last_click'][$map[$platform] ?? $platform]['revenue'] ?? 0);
            $out[$platform] = $amount > 0 ? round($revenue / $amount, 2) : null;
        }

        return $out;
    }
}
