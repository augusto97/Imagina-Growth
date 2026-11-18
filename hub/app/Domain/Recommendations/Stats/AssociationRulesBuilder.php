<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Stats;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de asociación «comprados juntos» (inteligencia.md §4.5) sobre los
 * pedidos pagados de 12 meses, por sitio. Para cada par A → B:
 *
 * - soporte = pedidos con A y B ÷ pedidos
 * - confianza = pedidos con A y B ÷ pedidos con A
 * - lift = confianza ÷ (pedidos con B ÷ pedidos)
 *
 * Se guardan los pares con al menos `min_pair_orders` pedidos, confianza mínima
 * y lift mayor que 1 (más frecuentes juntos que por azar), hasta 20 por producto.
 */
final class AssociationRulesBuilder
{
    /** @return array{orders: int, rules: int} */
    public function build(Workspace $workspace, Site $site, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cfg = (array) config('igr-commerce.recommendations.associations');
        $from = $now->subDays((int) ($cfg['window_days'] ?? 365))->format('Y-m-d H:i:s');
        $minPairs = max(1, (int) ($cfg['min_pair_orders'] ?? 3));
        $minConfidence = (float) ($cfg['min_confidence'] ?? 0.05);
        $minLift = (float) ($cfg['min_lift'] ?? 1.0);
        $maxPerProduct = max(1, (int) ($cfg['max_per_product'] ?? 20));

        // Productos distintos por pedido (sin variaciones: el producto padre).
        $items = 'SELECT DISTINCT i.order_id, i.external_product_id AS pid
                  FROM order_items i JOIN orders o ON o.id = i.order_id
                  WHERE o.workspace_id = ? AND o.site_id = ? AND o.is_paid = 1
                    AND o.status NOT IN (\'cancelled\', \'refunded\', \'failed\') AND o.created_at >= ?';
        $bind = [$workspace->id, $site->id, $from];

        $orders = (int) DB::selectOne("SELECT COUNT(DISTINCT t.order_id) AS n FROM ({$items}) t", $bind)->n;
        $productOrders = [];
        foreach (DB::select("SELECT t.pid, COUNT(*) AS n FROM ({$items}) t GROUP BY t.pid", $bind) as $row) {
            $productOrders[(string) $row->pid] = (int) $row->n;
        }
        $pairs = $orders === 0 ? [] : DB::select(
            "WITH t AS ({$items})
             SELECT a.pid AS a, b.pid AS b, COUNT(*) AS n
             FROM t a JOIN t b ON a.order_id = b.order_id AND a.pid <> b.pid
             GROUP BY a.pid, b.pid
             HAVING COUNT(*) >= ?",
            [...$bind, $minPairs],
        );

        /** @var array<string, list<array<string, mixed>>> $byProduct */
        $byProduct = [];
        foreach ($pairs as $pair) {
            $a = (string) $pair->a;
            $b = (string) $pair->b;
            $n = (int) $pair->n;
            $rule = self::rule($n, $productOrders[$a] ?? 0, $productOrders[$b] ?? 0, $orders);
            if ($rule === null || $rule['confidence'] < $minConfidence || $rule['lift'] <= $minLift) {
                continue;
            }
            $byProduct[$a][] = ['related' => $b, 'pair_orders' => $n] + $rule;
        }

        $rows = [];
        $computedAt = $now->format('Y-m-d H:i:s');
        foreach ($byProduct as $product => $rules) {
            usort($rules, fn (array $x, array $y) => [$y['lift'], $y['confidence'], $y['pair_orders']] <=> [$x['lift'], $x['confidence'], $x['pair_orders']]);
            foreach (array_slice($rules, 0, $maxPerProduct) as $rule) {
                $rows[] = [
                    'workspace_id' => $workspace->id, 'site_id' => $site->id,
                    'product_id' => mb_substr((string) $product, 0, 64), 'related_product_id' => mb_substr((string) $rule['related'], 0, 64),
                    'pair_orders' => $rule['pair_orders'],
                    'support' => round($rule['support'], 6), 'confidence' => round($rule['confidence'], 6), 'lift' => round(min($rule['lift'], 999999.9999), 4),
                    'computed_at' => $computedAt,
                ];
            }
        }

        DB::transaction(function () use ($workspace, $site, $rows): void {
            DB::table('product_associations')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('product_associations')->insert($chunk);
            }
        });

        return ['orders' => $orders, 'rules' => count($rows)];
    }

    /**
     * Métricas de una regla A → B.
     *
     * @return array{support: float, confidence: float, lift: float}|null
     */
    public static function rule(int $pairOrders, int $aOrders, int $bOrders, int $orders): ?array
    {
        if ($pairOrders <= 0 || $aOrders <= 0 || $bOrders <= 0 || $orders <= 0) {
            return null;
        }
        $support = $pairOrders / $orders;
        $confidence = $pairOrders / $aOrders;
        $lift = $confidence / ($bOrders / $orders);

        return ['support' => $support, 'confidence' => $confidence, 'lift' => $lift];
    }
}
