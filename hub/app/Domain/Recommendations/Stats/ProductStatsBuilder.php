<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Stats;

use App\Analytics\Ch;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Popularidad de cada producto para las recomendaciones (M16): unidades vendidas
 * en 7, 30 y 90 días (pedidos pagados), vistas de 30 días (ClickHouse) y el
 * puntaje de tendencia = unidades de 7 días ÷ (promedio semanal de las 4
 * semanas anteriores + 1). Corre cada 3 horas y, la primera vez que un sitio
 * pide recomendaciones, al momento.
 */
final class ProductStatsBuilder
{
    public function build(Workspace $workspace, Site $site, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $days = (int) config('igr-commerce.recommendations.best_sellers_days', 90);
        $at = fn (int $d) => $now->subDays($d)->format('Y-m-d H:i:s');

        // Una sola pasada sobre los pedidos de la ventana; los productos sin ventas quedan en 0.
        DB::statement(
            "UPDATE products p
             LEFT JOIN (
                SELECT i.external_product_id AS pid,
                       SUM(CASE WHEN o.created_at >= ? THEN i.qty ELSE 0 END) AS s7,
                       SUM(CASE WHEN o.created_at >= ? THEN i.qty ELSE 0 END) AS s30,
                       SUM(i.qty) AS s90,
                       SUM(CASE WHEN o.created_at < ? AND o.created_at >= ? THEN i.qty ELSE 0 END) AS prev28
                FROM order_items i
                JOIN orders o ON o.id = i.order_id
                WHERE o.workspace_id = ? AND o.site_id = ? AND o.is_paid = 1
                  AND o.status NOT IN ('cancelled', 'refunded', 'failed')
                  AND o.created_at >= ?
                GROUP BY i.external_product_id
             ) s ON s.pid = p.external_id
             SET p.purchases_7d = COALESCE(s.s7, 0),
                 p.purchases_30d = COALESCE(s.s30, 0),
                 p.purchases_90d = COALESCE(s.s90, 0),
                 p.trend_score = CASE WHEN COALESCE(s.s7, 0) > 0 THEN ROUND(s.s7 / (COALESCE(s.prev28, 0) / 4 + 1), 4) ELSE 0 END,
                 p.stats_computed_at = ?
             WHERE p.workspace_id = ? AND p.site_id = ?",
            [$at(7), $at(30), $at(7), $at(35), $workspace->id, $site->id, $at(max($days, 35)), $now->format('Y-m-d H:i:s'), $workspace->id, $site->id],
        );

        $this->views($workspace, $site, $now);
        Cache::forget(self::readyKey($workspace->id, $site->id));
    }

    /** Si el sitio nunca se calculó (recién conectado), lo calcula ahora. */
    public function ensure(Workspace $workspace, Site $site): void
    {
        $ready = Cache::remember(self::readyKey($workspace->id, $site->id), 3600, fn () => DB::table('products')
            ->where('workspace_id', $workspace->id)->where('site_id', $site->id)->whereNotNull('stats_computed_at')->exists() ? 1 : 0);
        if ($ready === 1) {
            return;
        }
        $hasProducts = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->exists();
        if ($hasProducts) {
            // Varias peticiones a la vez en un sitio nuevo: solo una calcula, las demás siguen con lo que haya.
            Cache::lock(self::readyKey($workspace->id, $site->id).':lock', 120)->get(fn () => $this->build($workspace, $site));
        }
    }

    public static function readyKey(string $workspaceId, string $siteId): string
    {
        return RedisKey::for($workspaceId, 'reco:stats_ready:'.$siteId);
    }

    /** Vistas de 30 días desde ClickHouse. Si ClickHouse no responde, se conservan las anteriores. */
    private function views(Workspace $workspace, Site $site, CarbonImmutable $now): void
    {
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT product_id, count() AS views FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND site_id = {site:UInt32} AND event = 'product.viewed'
                   AND occurred_at >= {from:DateTime64(3)} AND product_id != ''
                 GROUP BY product_id",
                ['site' => $site->ch_id, 'from' => $now->subDays(30)->utc()->format('Y-m-d H:i:s.v')],
            );
        } catch (Throwable $e) {
            Log::warning('igr.reco.views_unavailable', ['workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return;
        }

        DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->update(['views_30d' => 0]);
        foreach (array_chunk($rows, 500) as $chunk) {
            $cases = [];
            $bindings = [];
            $ids = [];
            foreach ($chunk as $row) {
                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, (string) $row['product_id'], (int) $row['views']);
                $ids[] = (string) $row['product_id'];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            DB::update(
                'UPDATE products SET views_30d = CASE external_id '.implode(' ', $cases).' ELSE views_30d END WHERE workspace_id = ? AND site_id = ? AND external_id IN ('.$in.')',
                [...$bindings, $workspace->id, $site->id, ...$ids],
            );
        }
        DB::update(
            'UPDATE products SET view_to_purchase_30d = CASE WHEN views_30d > 0 THEN LEAST(ROUND(purchases_30d / views_30d, 4), 999) ELSE NULL END WHERE workspace_id = ? AND site_id = ?',
            [$workspace->id, $site->id],
        );
    }
}
