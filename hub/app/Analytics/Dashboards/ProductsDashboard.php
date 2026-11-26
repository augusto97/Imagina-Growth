<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 5. Productos: vistos frente a vendidos, conversión vista → compra, abandono
 * en el carrito, productos de entrada (primera compra de los clientes con
 * mayor LTV), comprados juntos (soporte, confianza, lift) e inventario frente
 * a días restantes.
 */
final class ProductsDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        [$fsql, $params] = $filters->eventsSql();
        $behavior = Ch::forWorkspace($workspace)->select(
            "SELECT product_id, countIf(event = 'product.viewed') AS views, countIf(event = 'cart.item_added') AS carts
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND event IN ('product.viewed', 'cart.item_added') AND product_id != ''
               AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}{$fsql}
             GROUP BY product_id",
            $params + self::chRange($period),
        );
        $byProduct = [];
        foreach ($behavior as $b) {
            $byProduct[(string) $b['product_id']] = ['views' => (int) $b['views'], 'carts' => (int) $b['carts']];
        }

        $sales = $this->paidOrders($workspace, $period, $filters, 'o')->join('order_items as i', 'i.order_id', '=', 'o.id')
            ->groupBy('i.external_product_id')
            ->selectRaw('i.external_product_id AS pid, MAX(i.name) AS name, COUNT(DISTINCT o.id) AS orders, SUM(i.qty) AS units, SUM(i.line_total) AS revenue')
            ->get()->keyBy('pid');
        $catalog = DB::table('products')->where('workspace_id', $workspace->id)->whereNull('deleted_at')
            ->get(['external_id', 'name', 'stock_qty', 'stock_status', 'price'])->keyBy('external_id');
        $daily = $this->dailyUnits($workspace);

        $ids = array_unique(array_merge(array_keys($byProduct), $sales->keys()->map(fn ($k) => (string) $k)->all()));
        $rows = [];
        foreach ($ids as $pid) {
            $s = $sales->get($pid);
            $b = $byProduct[$pid] ?? ['views' => 0, 'carts' => 0];
            $p = $catalog->get($pid);
            $orders = (int) ($s->orders ?? 0);
            $perDay = $daily[$pid] ?? 0.0;
            $rows[] = [
                'product_id' => (string) $pid,
                'name' => (string) ($s->name ?? $p->name ?? $pid),
                'views' => $b['views'],
                'add_to_carts' => $b['carts'],
                'orders' => $orders,
                'units' => round((float) ($s->units ?? 0), 2),
                'revenue' => round((float) ($s->revenue ?? 0), 2),
                'view_to_purchase' => self::ratio($orders, $b['views']),
                'cart_abandonment' => $b['carts'] > 0 ? round(max(0, 1 - $orders / $b['carts']), 4) : null,
                'stock_qty' => $p !== null && $p->stock_qty !== null ? (float) $p->stock_qty : null,
                'days_of_stock' => $p !== null && $p->stock_qty !== null && $perDay > 0 ? round((float) $p->stock_qty / $perDay, 1) : null,
            ];
        }
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['views'] <=> $a['views']);

        return [
            'products' => array_slice($rows, 0, 100),
            'viewed_not_sold' => array_slice(array_values(array_filter(
                (function () use ($rows) {
                    usort($rows, fn ($a, $b) => $b['views'] <=> $a['views']);

                    return $rows;
                })(),
                fn ($r) => $r['views'] >= 50 && $r['view_to_purchase'] < 0.005,
            )), 0, 10),
            // No dependen del periodo elegido (toda la historia y 12 meses): una vez por hora.
            'entry_products' => MetricQueryRunner::rememberHourly($workspace, 'products:entry', fn () => $this->entryProducts($workspace)),
            'bought_together' => MetricQueryRunner::rememberHourly($workspace, 'products:together', fn () => $this->boughtTogether($workspace)),
        ];
    }

    /** @return array<string, float> unidades por día en los últimos 30 días */
    private function dailyUnits(Workspace $workspace): array
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->groupBy('i.external_product_id')->selectRaw('i.external_product_id AS pid, SUM(i.qty) / 30 AS per_day')
            ->pluck('per_day', 'pid')->map(fn ($v) => (float) $v)->all();
    }

    /**
     * Productos de la primera compra de los clientes, ordenados por el LTV
     * promedio de esos clientes.
     *
     * @return list<array<string, mixed>>
     */
    private function entryProducts(Workspace $workspace): array
    {
        return array_values(DB::table('orders as o')->join('order_items as i', 'i.order_id', '=', 'o.id')->join('contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_first_order', true)->where('o.is_paid', true)
            ->groupBy('i.external_product_id')
            ->havingRaw('COUNT(DISTINCT o.contact_id) >= 5')
            ->selectRaw('i.external_product_id AS product_id, MAX(i.name) AS name, COUNT(DISTINCT o.contact_id) AS customers, AVG(c.revenue_total_ws) AS avg_ltv, AVG(c.orders_count) AS avg_orders')
            ->orderByDesc('avg_ltv')->limit(10)->get()
            ->map(fn ($r) => ['product_id' => (string) $r->product_id, 'name' => (string) $r->name, 'customers' => (int) $r->customers,
                'avg_ltv' => round((float) $r->avg_ltv, 2), 'avg_orders' => round((float) $r->avg_orders, 2)])->all());
    }

    /**
     * Reglas de asociación entre pares de productos del mismo pedido (12 meses).
     *
     * @return list<array<string, mixed>>
     */
    private function boughtTogether(Workspace $workspace): array
    {
        $since = CarbonImmutable::now()->subMonths(12);
        $base = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', $since);
        $orders = (int) (clone $base)->distinct()->count('o.id');
        if ($orders === 0) {
            return [];
        }
        $single = (clone $base)->groupBy('i.external_product_id')->selectRaw('i.external_product_id AS pid, MAX(i.name) AS name, COUNT(DISTINCT o.id) AS n')
            ->get()->keyBy('pid');
        $pairs = DB::table('order_items as a')->join('order_items as b', function ($j): void {
            $j->on('a.order_id', '=', 'b.order_id')->whereColumn('a.external_product_id', '<', 'b.external_product_id');
        })->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', $since)
            ->groupBy('a.external_product_id', 'b.external_product_id')
            ->havingRaw('COUNT(DISTINCT a.order_id) >= 3')
            ->selectRaw('a.external_product_id AS pa, b.external_product_id AS pb, COUNT(DISTINCT a.order_id) AS n')
            ->orderByDesc('n')->limit(200)->get();

        $out = [];
        foreach ($pairs as $p) {
            $sa = $single->get($p->pa);
            $sb = $single->get($p->pb);
            $na = (int) ($sa->n ?? 0);
            $nb = (int) ($sb->n ?? 0);
            if ($sa === null || $sb === null || $na === 0 || $nb === 0) {
                continue;
            }
            $support = (int) $p->n / $orders;
            $out[] = [
                'a' => ['product_id' => (string) $p->pa, 'name' => (string) $sa->name],
                'b' => ['product_id' => (string) $p->pb, 'name' => (string) $sb->name],
                'orders' => (int) $p->n,
                'support' => round($support, 5),
                'confidence' => round((int) $p->n / $na, 4),
                'lift' => round($support / (($na / $orders) * ($nb / $orders)), 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['lift'] <=> $a['lift']);

        return array_slice($out, 0, 10);
    }
}
