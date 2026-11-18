<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Analytics\Ch;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Intelligence\Predictions\Support\Decay;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Afinidad personal por categoría y marca (inteligencia.md §4.5): vista = 1,
 * carrito = 3, compra = 5, reseña positiva = 2, con vida media de 30 días. El top 5
 * va a `contacts.category_affinity` (`[{category_id, score}]`, el formato que lee
 * el motor de recomendaciones) y `contacts.brand_affinity` (`[{brand, score}]`).
 */
final class ComputeAffinity
{
    /** @return array{status: string} */
    public function handle(Workspace $workspace): array
    {
        $cfg = (array) config('igr-predictions.affinity');
        $w = (array) $cfg['weights'];
        $hl = (float) ($cfg['half_life_days'] ?? 30);
        $since = now()->subDays((int) ($cfg['window_days'] ?? 180));
        $now = now()->getTimestamp();
        $top = (int) ($cfg['top'] ?? 5);

        $catalog = [];
        foreach (DB::table('products')->where('workspace_id', $workspace->id)->whereNull('deleted_at')->select(['external_id', 'category_ids', 'brand'])->cursor() as $p) {
            $cats = json_decode((string) $p->category_ids, true);
            $catalog[(string) $p->external_id] = [is_array($cats) ? array_map('strval', $cats) : [], $p->brand !== null && $p->brand !== '' ? (string) $p->brand : null];
        }
        $cat = [];
        $brand = [];
        $add = function (string $cid, array $cats, ?string $b, float $score) use (&$cat, &$brand): void {
            foreach ($cats as $c) {
                $cat[$cid][$c] = ($cat[$cid][$c] ?? 0) + $score;
            }
            if ($b !== null) {
                $brand[$cid][$b] = ($brand[$cid][$b] ?? 0) + $score;
            }
        };

        // Vistas y carritos (ClickHouse), con los anónimos ya vinculados a un contacto.
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT if(e.contact_id != '', e.contact_id, im.contact_id) AS cid, e.product_id AS pid, e.event AS ev,
                        sum(pow(0.5, dateDiff('second', e.occurred_at, now64(3)) / 86400 / {hl:Float64})) AS w
                 FROM events AS e
                 LEFT JOIN (SELECT anonymous_id, argMax(contact_id, linked_at) AS contact_id FROM identity_map
                            WHERE workspace_id = {workspace_id:UInt32} GROUP BY anonymous_id) AS im ON im.anonymous_id = e.anonymous_id
                 WHERE e.workspace_id = {workspace_id:UInt32} AND e.event IN ('product.viewed', 'cart.item_added')
                   AND e.occurred_at >= {from:DateTime64(3)} AND e.product_id != ''
                 GROUP BY cid, pid, ev HAVING cid != ''",
                ['hl' => $hl, 'from' => $since->utc()->format('Y-m-d H:i:s.v')],
            );
        } catch (Throwable $e) {
            Log::warning('igr.predictions.affinity_events_unavailable', ['workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            $rows = [];
        }
        foreach ($rows as $r) {
            [$cats, $b] = $catalog[(string) $r['pid']] ?? [[], null];
            $add((string) $r['cid'], $cats, $b, (float) $r['w'] * (float) ($r['ev'] === 'cart.item_added' ? $w['cart'] : $w['view']));
        }

        // Compras.
        $items = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->whereNotNull('o.contact_id')->where('o.created_at', '>=', $since)
            ->select(['o.contact_id', 'o.created_at', 'i.category_ids', 'i.brand'])->cursor();
        foreach ($items as $i) {
            $cats = json_decode((string) $i->category_ids, true);
            $add((string) $i->contact_id, is_array($cats) ? array_map('strval', $cats) : [], $i->brand !== null && $i->brand !== '' ? (string) $i->brand : null,
                (float) $w['purchase'] * Decay::weight((int) strtotime((string) $i->created_at.' UTC'), $now, $hl));
        }

        // Reseñas positivas (4 o 5 estrellas).
        $reviews = DB::table('reviews')->where('workspace_id', $workspace->id)->whereNotNull('contact_id')->where('rating', '>=', 4)
            ->whereNotNull('external_product_id')->where('created_at', '>=', $since)->select(['contact_id', 'external_product_id', 'created_at'])->cursor();
        foreach ($reviews as $r) {
            [$cats, $b] = $catalog[(string) $r->external_product_id] ?? [[], null];
            $add((string) $r->contact_id, $cats, $b, (float) $w['review'] * Decay::weight((int) strtotime((string) $r->created_at.' UTC'), $now, $hl));
        }

        $updates = [];
        foreach (array_unique([...array_keys($cat), ...array_keys($brand)]) as $cid) {
            $updates[(string) $cid] = [
                'category_affinity' => self::top($cat[$cid] ?? [], $top, 'category_id'),
                'brand_affinity' => self::top($brand[$cid] ?? [], $top, 'brand'),
            ];
        }
        $existing = DB::table('contacts')->where('workspace_id', $workspace->id)->whereIn('id', array_keys($updates))->pluck('id')->all();
        Bulk::contacts($workspace->id, array_intersect_key($updates, array_flip(array_map('strval', $existing))));
        PredictionRun::record($workspace->id, 'affinity', 'completed', [
            'contacts' => count($existing), 'from_events' => count($rows), 'half_life_days' => $hl,
        ], ['weights' => $w]);

        return ['status' => 'completed'];
    }

    /**
     * @param  array<string, float>  $scores
     * @return list<array<string, string|float>>
     */
    public static function top(array $scores, int $n, string $key): array
    {
        arsort($scores);
        $out = [];
        foreach (array_slice($scores, 0, $n, true) as $k => $v) {
            $out[] = [$key => (string) $k, 'score' => round($v, 3)];
        }

        return $out;
    }
}
