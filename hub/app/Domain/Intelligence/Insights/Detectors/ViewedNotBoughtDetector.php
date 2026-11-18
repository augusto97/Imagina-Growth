<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #3 Productos muy vistos que poco se venden: tráfico alto (cuartil superior de
 * vistas en 30 días) y conversión vista → compra en el decil inferior. Hay que
 * revisar precio, fotos, stock y descripción.
 */
final class ViewedNotBoughtDetector implements Detector
{
    private const MIN_VIEWS = 30;

    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'viewed_not_bought';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(30);
        $views = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT product_id AS pid, count() AS views FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'product.viewed' AND product_id != '' AND occurred_at >= {since:DateTime64(3)}
            GROUP BY product_id HAVING views >= {min:UInt32}
            SQL, ['since' => Data::ch($since), 'min' => self::MIN_VIEWS]);
        if (count($views) < 5) {
            return [];
        }
        $orders = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', $since->utc())
            ->whereIn('i.external_product_id', array_column($views, 'pid'))
            ->groupBy('i.external_product_id')->selectRaw('i.external_product_id AS pid, COUNT(DISTINCT i.order_id) AS orders')
            ->pluck('orders', 'pid');
        $rows = [];
        foreach ($views as $v) {
            $n = (int) $v['views'];
            $rows[(string) $v['pid']] = ['views' => $n, 'orders' => (int) ($orders[(string) $v['pid']] ?? 0), 'rate' => (int) ($orders[(string) $v['pid']] ?? 0) / $n];
        }
        $rates = array_column($rows, 'rate');
        $median = Data::percentile($rates, 0.5);
        $p10 = Data::percentile($rates, 0.1);
        $p75Views = Data::percentile(array_map('floatval', array_column($rows, 'views')), 0.75);
        $flagged = array_filter($rows, fn (array $r) => $r['views'] >= $p75Views && $r['rate'] <= $p10 && $r['rate'] < $median);
        if ($flagged === [] || $median <= 0) {
            return [];
        }
        $products = DB::table('products')->where('workspace_id', $workspace->id)->whereIn('external_id', array_keys($flagged))->whereNull('deleted_at')
            ->get(['external_id', 'name', 'price', 'stock_status'])->keyBy('external_id');
        $list = [];
        $gapOrders = 0.0;
        $prices = [];
        foreach ($flagged as $pid => $r) {
            $p = $products->get($pid);
            if ($p === null) {
                continue;
            }
            $gap = ($median - $r['rate']) * $r['views'];
            $gapOrders += $gap;
            $prices[] = (float) $p->price;
            $list[] = ['product_id' => (string) $pid, 'name' => (string) $p->name, 'views' => $r['views'], 'orders' => $r['orders'],
                'conversion' => round($r['rate'], 4), 'price' => round((float) $p->price, 2), 'stock_status' => (string) $p->stock_status];
        }
        if ($list === []) {
            return [];
        }
        usort($list, fn ($a, $b) => $b['views'] <=> $a['views']);
        $avgPrice = array_sum($prices) / count($prices);
        $share = $this->assumptions->value('product_fix_share');
        $formula = Impact::of(
            new Term(__('pedidos que faltan frente a la conversión mediana'), round($gapOrders, 1), 'count'),
            new Term(__('que se recupera al mejorarlos'), $share, 'rate', 'product_fix_share'),
            new Term(__('de precio promedio'), $avgPrice, 'money'),
        );

        return [new InsightCandidate(
            fingerprint: 'products',
            severity: 'opportunity',
            title: count($list) === 1
                ? __(':name se ve mucho y casi no se vende', ['name' => $list[0]['name']])
                : __(':n productos muy vistos casi no se venden', ['n' => count($list)]),
            facts: ['products' => array_slice($list, 0, 10), 'median_conversion' => round($median, 4), 'p10_conversion' => round($p10, 4),
                'window_days' => 30, 'gap_orders' => round($gapOrders, 1)],
            assumptions: [__('Revisa el precio, las fotos, el stock y la descripción: suelen ser la causa.')],
            action: ['label' => __('Revisa estos productos en Analítica'), 'to' => 'analitica/productos'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
        )];
    }
}
