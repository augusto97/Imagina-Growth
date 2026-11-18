<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Engine;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los productos que se pueden recomendar y su tarjeta pública (api-connector.md
 * §8.3): publicados, no borrados, con stock (o bajo pedido), sin variaciones.
 * La calificación sale de las reseñas publicadas de M15 y, si el producto no
 * tiene, de la de WooCommerce.
 */
final class ProductCards
{
    public const COLUMNS = ['external_id', 'name', 'price', 'regular_price', 'sale_price', 'currency', 'image_url', 'permalink', 'rating_avg', 'rating_count',
        'category_ids', 'purchases_7d', 'purchases_30d', 'purchases_90d', 'trend_score'];

    public static function eligible(string $workspaceId, string $siteId): Builder
    {
        return DB::table('products')
            ->where('workspace_id', $workspaceId)->where('site_id', $siteId)
            ->where('status', 'publish')->whereNull('deleted_at')->whereNull('parent_external_id')
            ->where(fn (Builder $q) => $q->whereNull('stock_status')->orWhereIn('stock_status', ['instock', 'onbackorder']));
    }

    /**
     * Filtra por cualquiera de las categorías (el catálogo puede guardar los IDs como texto o número).
     *
     * @param  list<string>  $categories
     */
    public static function inCategories(Builder $query, array $categories): Builder
    {
        if ($categories === []) {
            return $query;
        }

        return $query->where(function (Builder $w) use ($categories): void {
            foreach (array_slice($categories, 0, 20) as $category) {
                $w->orWhereJsonContains('category_ids', $category);
                if (ctype_digit($category)) {
                    $w->orWhereJsonContains('category_ids', (int) $category);
                }
            }
        });
    }

    /**
     * @param  list<\stdClass>  $rows  filas con self::COLUMNS
     * @return list<array{id: string, name: string, price: string, regular_price: string|null, sale_price: string|null, currency: string, image: string|null, url: string|null, rating_avg: float|null, rating_count: int}>
     */
    public static function present(string $workspaceId, string $siteId, array $rows, string $currency): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn (\stdClass $r) => (string) $r->external_id, $rows);
        $reviews = [];
        foreach (DB::table('reviews')->where('workspace_id', $workspaceId)->where('site_id', $siteId)->where('status', 'published')
            ->whereIn('external_product_id', $ids)->groupBy('external_product_id')
            ->selectRaw('external_product_id AS pid, AVG(rating) AS avg_rating, COUNT(*) AS n')->get() as $r) {
            $reviews[(string) $r->pid] = ['avg' => round((float) $r->avg_rating, 2), 'count' => (int) $r->n];
        }
        $money = fn (mixed $v): ?string => $v === null || $v === '' ? null : number_format((float) $v, 2, '.', '');

        return array_map(function (\stdClass $p) use ($reviews, $money, $currency): array {
            $id = (string) $p->external_id;
            $own = $reviews[$id] ?? null;
            $wooCount = (int) ($p->rating_count ?? 0);

            return [
                'id' => $id,
                'name' => (string) $p->name,
                'price' => (string) $money($p->price),
                'regular_price' => $money($p->regular_price ?? null),
                'sale_price' => $money($p->sale_price ?? null),
                'currency' => is_string($p->currency ?? null) && $p->currency !== '' ? $p->currency : $currency,
                'image' => is_string($p->image_url ?? null) && $p->image_url !== '' ? $p->image_url : null,
                'url' => is_string($p->permalink ?? null) && $p->permalink !== '' ? $p->permalink : null,
                'rating_avg' => $own !== null ? $own['avg'] : ($wooCount > 0 && $p->rating_avg !== null ? round((float) $p->rating_avg, 2) : null),
                'rating_count' => $own !== null ? $own['count'] : $wooCount,
            ];
        }, $rows);
    }

    /**
     * Categorías de un producto como texto.
     *
     * @return list<string>
     */
    public static function categoriesOf(\stdClass $row): array
    {
        $raw = is_string($row->category_ids ?? null) ? json_decode($row->category_ids, true) : ($row->category_ids ?? []);

        return array_values(array_map('strval', array_filter((array) $raw, fn ($v) => is_string($v) || is_int($v))));
    }
}
