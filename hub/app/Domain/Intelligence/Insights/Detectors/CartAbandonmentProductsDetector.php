<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #4 Productos con alto abandono en el carrito: se agregan mucho y se compran
 * poco, con un abandono al menos 20 puntos por encima de la mediana y del 70 %
 * o más (30 días, mínimo 20 veces agregado).
 */
final class CartAbandonmentProductsDetector implements Detector
{
    private const MIN_ADDS = 20;

    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'cart_abandonment_products';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(30);
        $adds = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT product_id AS pid, count() AS adds FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'cart.item_added' AND product_id != '' AND occurred_at >= {since:DateTime64(3)}
            GROUP BY product_id HAVING adds >= {min:UInt32}
            SQL, ['since' => Data::ch($since), 'min' => self::MIN_ADDS]);
        if (count($adds) < 3) {
            return [];
        }
        $bought = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('o.created_at', '>=', $since->utc())
            ->whereIn('i.external_product_id', array_column($adds, 'pid'))
            ->groupBy('i.external_product_id')->selectRaw('i.external_product_id AS pid, COUNT(DISTINCT i.order_id) AS orders')
            ->pluck('orders', 'pid');
        $rows = [];
        foreach ($adds as $a) {
            $n = (int) $a['adds'];
            $orders = (int) ($bought[(string) $a['pid']] ?? 0);
            $rows[(string) $a['pid']] = ['adds' => $n, 'orders' => $orders, 'abandon' => 1 - min(1.0, $orders / $n)];
        }
        $median = Data::percentile(array_column($rows, 'abandon'), 0.5);
        $flagged = array_filter($rows, fn (array $r) => $r['abandon'] >= 0.7 && $r['abandon'] >= $median + 0.2);
        if ($flagged === []) {
            return [];
        }
        $products = DB::table('products')->where('workspace_id', $workspace->id)->whereIn('external_id', array_keys($flagged))->whereNull('deleted_at')
            ->get(['external_id', 'name', 'price', 'stock_status'])->keyBy('external_id');
        $list = [];
        $excess = 0.0;
        $prices = [];
        foreach ($flagged as $pid => $r) {
            $p = $products->get($pid);
            if ($p === null) {
                continue;
            }
            $excess += ($r['abandon'] - $median) * $r['adds'];
            $prices[] = (float) $p->price;
            $list[] = ['product_id' => (string) $pid, 'name' => (string) $p->name, 'adds' => $r['adds'], 'orders' => $r['orders'],
                'abandon_rate' => round($r['abandon'], 4), 'price' => round((float) $p->price, 2)];
        }
        if ($list === []) {
            return [];
        }
        usort($list, fn ($a, $b) => $b['adds'] <=> $a['adds']);
        $share = $this->assumptions->value('cart_fix_share');
        $formula = Impact::of(
            new Term(__('carritos abandonados por encima de lo normal'), round($excess, 1), 'count'),
            new Term(__('que se recupera'), $share, 'rate', 'cart_fix_share'),
            new Term(__('de precio promedio'), array_sum($prices) / count($prices), 'money'),
        );
        $flow = Data::libraryFlowStatus('abandoned_cart');
        $action = $flow === Flow::ACTIVE
            ? ['label' => __('Revisa el precio con envío y los costos que aparecen en el carrito'), 'to' => 'analitica/productos']
            : ['label' => __('Activa la automatización de carrito abandonado'), 'to' => 'flujos?tab=biblioteca', 'kind' => 'activate_flow', 'payload' => ['library_key' => 'abandoned_cart']];

        return [new InsightCandidate(
            fingerprint: 'products',
            severity: 'warning',
            title: count($list) === 1
                ? __(':name se abandona en el carrito el :rate de las veces', ['name' => $list[0]['name'], 'rate' => Money::percent($list[0]['abandon_rate'], 0)])
                : __(':n productos se abandonan mucho en el carrito', ['n' => count($list)]),
            facts: ['products' => array_slice($list, 0, 10), 'median_abandon_rate' => round($median, 4), 'window_days' => 30,
                'excess_abandoned' => round($excess, 1), 'abandoned_cart_flow' => $flow ?? 'missing'],
            assumptions: [__('Un costo que aparece tarde (envío, impuestos) o una variante sin stock suelen ser la causa.')],
            action: $action,
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
        )];
    }
}
