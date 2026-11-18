<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Engine;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Commerce\Data\RecommendationContext;
use App\Domain\Recommendations\Stats\ProductStatsBuilder;
use App\Domain\Recommendations\Support\VisitorResolver;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Motor de recomendaciones (modulos.md M16, inteligencia.md §4.5). Solo
 * recomienda productos publicados y con stock, nunca los que se excluyen, el
 * producto de la ficha ni lo que ya está en el carrito.
 *
 * | Tipo | Lógica | Sin señal |
 * |---|---|---|
 * | `para_ti` | afinidad por categoría (compra 5, carrito 3, vista 1, vida media de 30 días) + popularidad + «comprados juntos» de lo último que compró o vio; sin lo que ya compró | más vendidos |
 * | `bought_together` | reglas de asociación del producto, por lift | más vendidos de su categoría |
 * | `recently_viewed` | historial del navegador o del contacto | nada (el bloque se oculta) |
 * | `best_sellers_category` | unidades de 90 días de la categoría (sin categoría: de la tienda) | más vendidos de la tienda |
 * | `trending` | unidades de 7 días frente al promedio semanal de las 4 anteriores | más vendidos |
 * | `complete_cart` | reglas de asociación de lo que hay en el carrito | más vendidos de esas categorías |
 *
 * El cálculo pesado (popularidad y reglas) lo hacen los jobs; aquí solo hay
 * consultas por índice. Debe correr dentro del espacio de trabajo activo.
 */
final class RecommendationEngine implements RecommendationService
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly ProductStatsBuilder $stats,
        private readonly ViewHistory $history,
        private readonly VisitorResolver $visitors,
    ) {}

    public function recommend(string $kind, RecommendationContext $context, int $limit = 4): array
    {
        return $this->recommendWithSource($kind, $context, $limit)['products'];
    }

    /**
     * Igual que recommend() y además dice si se usó el respaldo (más vendidos)
     * porque no había señal para ese tipo.
     *
     * @return array{products: list<array{id: string, name: string, price: string, regular_price: string|null, sale_price: string|null, currency: string, image: string|null, url: string|null, rating_avg: float|null, rating_count: int}>, fallback: bool}
     */
    public function recommendWithSource(string $kind, RecommendationContext $context, int $limit = 4): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Tipo de recomendación desconocido: {$kind}");
        }
        $limit = max(1, min($limit, (int) config('igr-commerce.recommendations.max_limit', 12)));
        $workspace = $this->current->get();
        $site = Site::query()->find($context->siteId);
        if ($site === null) {
            return ['products' => [], 'fallback' => false];
        }
        $this->stats->ensure($workspace, $site);

        $exclude = array_values(array_unique(array_map('strval', $context->excludeProductIds)));
        [$rows, $fallback] = match ($kind) {
            'para_ti' => $this->forYou($workspace, $site, $context, $exclude, $limit),
            'bought_together' => $this->boughtTogether($workspace, $site, $context, $exclude, $limit),
            'recently_viewed' => [$this->recentlyViewed($workspace, $site, $context, $exclude, $limit), false],
            'best_sellers_category' => $this->bestSellersCategory($workspace, $site, $context, $exclude, $limit),
            'trending' => $this->trending($workspace, $site, $context, $exclude, $limit),
            'complete_cart' => $this->completeCart($workspace, $site, $context, $exclude, $limit),
        };

        return [
            'products' => ProductCards::present($workspace->id, $site->id, array_slice($rows, 0, $limit), $site->currency ?: ($workspace->currency ?: 'COP')),
            'fallback' => $fallback,
        ];
    }

    /**
     * Más vendidos (unidades de 90 días), opcionalmente de unas categorías.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $exclude
     * @return list<\stdClass>
     */
    public function bestSellers(Workspace $workspace, Site $site, array $categories, array $exclude, int $limit): array
    {
        $query = ProductCards::inCategories(ProductCards::eligible($workspace->id, $site->id), $categories)
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('external_id', $exclude))
            ->orderByDesc('purchases_90d')->orderByDesc('purchases_30d')->orderByRaw('COALESCE(rating_avg, 0) DESC')->orderBy('name')
            ->limit($limit);

        return array_values($query->get(ProductCards::COLUMNS)->all());
    }

    /**
     * @param  list<string>  $exclude
     * @return array{0: list<\stdClass>, 1: bool}
     */
    private function forYou(Workspace $workspace, Site $site, RecommendationContext $c, array $exclude, int $limit): array
    {
        $now = CarbonImmutable::now();
        $anonymous = $this->anonymousIds($c);
        $purchased = [];
        $seeds = [];
        /** @var array<string, float> $affinity */
        $affinity = [];
        $cfg = (array) config('igr-commerce.recommendations.affinity');
        $halfLife = max(1.0, (float) ($cfg['half_life_days'] ?? 30));
        $decay = fn (CarbonImmutable $at): float => 0.5 ** (max(0, $at->diffInDays($now)) / $halfLife);
        $add = function (array $categories, float $weight) use (&$affinity): void {
            foreach ($categories as $category) {
                $affinity[$category] = ($affinity[$category] ?? 0.0) + $weight;
            }
        };

        if ($c->contactId !== null) {
            $orders = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('o.workspace_id', $workspace->id)->where('o.contact_id', $c->contactId)->where('o.site_id', $site->id)
                ->whereNotIn('o.status', ['cancelled', 'failed'])
                ->orderByDesc('o.created_at')->limit(300)
                ->get(['i.external_product_id', 'i.category_ids', 'o.created_at']);
            foreach ($orders as $row) {
                $pid = (string) $row->external_product_id;
                $purchased[$pid] = true;
                if (count($seeds) < 5 && ! in_array($pid, $seeds, true)) {
                    $seeds[] = $pid;
                }
                $at = $row->created_at !== null ? CarbonImmutable::parse((string) $row->created_at) : $now;
                $add(ProductCards::categoriesOf($row), (float) ($cfg['purchase'] ?? 5.0) * $decay($at));
            }
            $stored = $this->storedAffinity($c->contactId);
            if ($stored !== []) {
                $affinity = $stored + $affinity; // la afinidad nocturna (Fase 4) manda si existe
            }
        }

        // Carritos de los últimos 30 días y vistas de 30 días.
        $cartProducts = [];
        foreach ($this->recentCarts($workspace, $site, $c, $anonymous, 30) as $cart) {
            $at = CarbonImmutable::parse((string) $cart->last_activity_at);
            foreach ((array) json_decode((string) $cart->items, true) as $item) {
                if (is_array($item) && isset($item['product_id'])) {
                    $cartProducts[(string) $item['product_id']] = $decay($at);
                }
            }
        }
        $views = [];
        foreach ($this->history->recent($workspace, $site->ch_id, $anonymous, $c->contactId, 30, 50) as $view) {
            $views[$view['product_id']] = $decay($view['at']);
            if (count($seeds) < 10 && ! in_array($view['product_id'], $seeds, true)) {
                $seeds[] = $view['product_id'];
            }
        }
        $signalIds = array_map('strval', array_keys($cartProducts + $views));
        if ($signalIds !== []) {
            $categoriesById = [];
            foreach (DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->whereIn('external_id', $signalIds)->get(['external_id', 'category_ids']) as $p) {
                $categoriesById[(string) $p->external_id] = ProductCards::categoriesOf($p);
            }
            foreach ($cartProducts as $pid => $w) {
                $add($categoriesById[(string) $pid] ?? [], (float) ($cfg['cart'] ?? 3.0) * $w);
            }
            foreach ($views as $pid => $w) {
                $add($categoriesById[(string) $pid] ?? [], (float) ($cfg['view'] ?? 1.0) * $w);
            }
        }

        $skip = array_values(array_unique(array_merge($exclude, array_map('strval', array_keys($purchased)), $c->cartProductIds, $c->productId !== null ? [$c->productId] : [])));
        if ($affinity === []) {
            return [$this->bestSellers($workspace, $site, [], $skip, $limit), true];
        }

        arsort($affinity);
        $top = array_slice($affinity, 0, max(1, (int) ($cfg['top_categories'] ?? 5)), true);
        $topCategories = array_map('strval', array_keys($top));
        $candidates = ProductCards::inCategories(ProductCards::eligible($workspace->id, $site->id), $topCategories)
            ->when($skip !== [], fn ($q) => $q->whereNotIn('external_id', $skip))
            ->orderByDesc('purchases_90d')->limit(200)->get(ProductCards::COLUMNS)->all();
        if ($candidates === []) {
            return [$this->bestSellers($workspace, $site, [], $skip, $limit), true];
        }

        $related = $this->associated($workspace, $site, $seeds);
        $maxAffinity = $top === [] ? 1.0 : (max($top) ?: 1.0);
        $maxSold = max(1, ...array_map(fn ($p) => (int) $p->purchases_90d, $candidates));
        $maxLift = $related === [] ? 1.0 : max($related);
        $scored = [];
        foreach ($candidates as $p) {
            $categoryScore = 0.0;
            foreach (ProductCards::categoriesOf($p) as $category) {
                $categoryScore = max($categoryScore, ($top[$category] ?? 0.0) / $maxAffinity);
            }
            $popularity = log1p((int) $p->purchases_90d) / log1p($maxSold);
            $boost = isset($related[(string) $p->external_id]) ? $related[(string) $p->external_id] / $maxLift : 0.0;
            $scored[] = [$categoryScore + 0.5 * $popularity + 0.5 * $boost, (int) $p->purchases_90d, (string) $p->name, $p];
        }
        usort($scored, fn (array $a, array $b) => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return [array_map(fn (array $s) => $s[3], array_slice($scored, 0, $limit)), false];
    }

    /**
     * @param  list<string>  $exclude
     * @return array{0: list<\stdClass>, 1: bool}
     */
    private function boughtTogether(Workspace $workspace, Site $site, RecommendationContext $c, array $exclude, int $limit): array
    {
        $skip = array_values(array_unique(array_merge($exclude, $c->cartProductIds, $c->productId !== null ? [$c->productId] : [])));
        if ($c->productId === null) {
            return [$this->bestSellers($workspace, $site, $c->categoryId !== null ? [$c->categoryId] : [], $skip, $limit), true];
        }
        $rows = $this->byOrder($workspace, $site, array_keys($this->associated($workspace, $site, [$c->productId])), $skip, $limit);
        if ($rows !== []) {
            return [$rows, false];
        }

        return [$this->categoryFallback($workspace, $site, [$c->productId], $c->categoryId, $skip, $limit), true];
    }

    /**
     * @param  list<string>  $exclude
     * @return list<\stdClass>
     */
    private function recentlyViewed(Workspace $workspace, Site $site, RecommendationContext $c, array $exclude, int $limit): array
    {
        $skip = array_merge($exclude, $c->productId !== null ? [$c->productId] : []);
        $ids = array_map(fn (array $v) => $v['product_id'], $this->history->recent($workspace, $site->ch_id, $this->anonymousIds($c), $c->contactId, 90, 30));

        return $this->byOrder($workspace, $site, $ids, $skip, $limit);
    }

    /**
     * @param  list<string>  $exclude
     * @return array{0: list<\stdClass>, 1: bool}
     */
    private function bestSellersCategory(Workspace $workspace, Site $site, RecommendationContext $c, array $exclude, int $limit): array
    {
        $skip = array_values(array_unique(array_merge($exclude, $c->cartProductIds, $c->productId !== null ? [$c->productId] : [])));
        $category = $c->categoryId;
        if ($category === null && $c->productId !== null) {
            $product = DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->where('external_id', $c->productId)->first(['category_ids']);
            $category = $product !== null ? (ProductCards::categoriesOf($product)[0] ?? null) : null;
        }
        if ($category === null) {
            return [$this->bestSellers($workspace, $site, [], $skip, $limit), false];
        }
        $rows = $this->bestSellers($workspace, $site, [$category], $skip, $limit);

        return $rows !== [] ? [$rows, false] : [$this->bestSellers($workspace, $site, [], $skip, $limit), true];
    }

    /**
     * @param  list<string>  $exclude
     * @return array{0: list<\stdClass>, 1: bool}
     */
    private function trending(Workspace $workspace, Site $site, RecommendationContext $c, array $exclude, int $limit): array
    {
        $skip = array_values(array_unique(array_merge($exclude, $c->cartProductIds, $c->productId !== null ? [$c->productId] : [])));
        $rows = ProductCards::eligible($workspace->id, $site->id)
            ->where('purchases_7d', '>=', max(1, (int) config('igr-commerce.recommendations.trending_min_units', 2)))
            ->when($skip !== [], fn ($q) => $q->whereNotIn('external_id', $skip))
            ->orderByDesc('trend_score')->orderByDesc('purchases_7d')->orderBy('name')->limit($limit)
            ->get(ProductCards::COLUMNS)->all();

        return $rows !== [] ? [array_values($rows), false] : [$this->bestSellers($workspace, $site, [], $skip, $limit), true];
    }

    /**
     * @param  list<string>  $exclude
     * @return array{0: list<\stdClass>, 1: bool}
     */
    private function completeCart(Workspace $workspace, Site $site, RecommendationContext $c, array $exclude, int $limit): array
    {
        $cart = array_values(array_unique(array_map('strval', $c->cartProductIds)));
        if ($cart === []) {
            $carts = $this->recentCarts($workspace, $site, $c, $this->anonymousIds($c), (int) config('igr-commerce.banners.pending_cart_days', 7), ['active', 'abandoned']);
            foreach ($carts as $row) {
                foreach ((array) json_decode((string) $row->items, true) as $item) {
                    if (is_array($item) && isset($item['product_id'])) {
                        $cart[] = (string) $item['product_id'];
                    }
                }
                break; // el carrito más reciente
            }
            $cart = array_values(array_unique($cart));
        }
        $skip = array_values(array_unique(array_merge($exclude, $cart, $c->productId !== null ? [$c->productId] : [])));
        if ($cart !== []) {
            $rows = $this->byOrder($workspace, $site, array_keys($this->associated($workspace, $site, $cart)), $skip, $limit);
            if ($rows !== []) {
                return [$rows, false];
            }
        }

        return [$this->categoryFallback($workspace, $site, $cart, $c->categoryId, $skip, $limit), true];
    }

    /**
     * Productos relacionados por reglas de asociación con alguno de los semilla,
     * con su mejor lift (del más fuerte al más débil).
     *
     * @param  list<string>  $seeds
     * @return array<string, float>
     */
    private function associated(Workspace $workspace, Site $site, array $seeds): array
    {
        if ($seeds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('product_associations')->where('workspace_id', $workspace->id)->where('site_id', $site->id)
            ->whereIn('product_id', array_slice($seeds, 0, 20))->orderByDesc('lift')->orderByDesc('confidence')->limit(200)
            ->get(['related_product_id', 'lift', 'confidence']) as $row) {
            $id = (string) $row->related_product_id;
            $score = (float) $row->lift * (0.5 + (float) $row->confidence);
            $out[$id] = max($out[$id] ?? 0.0, $score);
        }
        arsort($out);

        return $out;
    }

    /**
     * Productos elegibles con estos IDs, en el mismo orden.
     *
     * @param  list<string|int>  $ids
     * @param  list<string>  $skip
     * @return list<\stdClass>
     */
    private function byOrder(Workspace $workspace, Site $site, array $ids, array $skip, int $limit): array
    {
        $ids = array_values(array_diff(array_unique(array_map('strval', $ids)), $skip));
        if ($ids === []) {
            return [];
        }
        $rows = ProductCards::eligible($workspace->id, $site->id)->whereIn('external_id', array_slice($ids, 0, 100))->get(ProductCards::COLUMNS)->keyBy('external_id')->all();
        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $out[] = $rows[$id];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Más vendidos de las categorías de unos productos; si no hay, de la tienda.
     *
     * @param  list<string>  $products
     * @param  list<string>  $skip
     * @return list<\stdClass>
     */
    private function categoryFallback(Workspace $workspace, Site $site, array $products, ?string $categoryId, array $skip, int $limit): array
    {
        $categories = $categoryId !== null ? [$categoryId] : [];
        if ($products !== []) {
            foreach (DB::table('products')->where('workspace_id', $workspace->id)->where('site_id', $site->id)->whereIn('external_id', array_slice($products, 0, 20))->get(['category_ids']) as $p) {
                $categories = array_merge($categories, ProductCards::categoriesOf($p));
            }
        }
        $categories = array_values(array_unique($categories));
        $rows = $categories === [] ? [] : $this->bestSellers($workspace, $site, $categories, $skip, $limit);

        return $rows !== [] ? $rows : $this->bestSellers($workspace, $site, [], $skip, $limit);
    }

    /** @return list<string> */
    private function anonymousIds(RecommendationContext $c): array
    {
        $ids = [];
        $own = VisitorResolver::cleanAnonymousId($c->anonymousId);
        if ($own !== null) {
            $ids[] = $own;
        }
        if ($c->contactId !== null) {
            $ids = array_merge($ids, $this->visitors->anonymousIds($c->contactId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Carritos con productos de la persona (por contacto o por navegador), del más reciente al más viejo.
     *
     * @param  list<string>  $anonymous
     * @param  list<string>  $statuses
     * @return list<\stdClass>
     */
    private function recentCarts(Workspace $workspace, Site $site, RecommendationContext $c, array $anonymous, int $days, array $statuses = ['active', 'abandoned', 'converted', 'recovered']): array
    {
        if ($c->contactId === null && $anonymous === []) {
            return [];
        }

        return array_values(DB::table('carts')->where('workspace_id', $workspace->id)->where('site_id', $site->id)
            ->whereIn('status', $statuses)->where('items_count', '>', 0)->where('last_activity_at', '>=', now()->subDays($days))
            ->where(function ($q) use ($c, $anonymous): void {
                if ($c->contactId !== null) {
                    $q->orWhere('contact_id', $c->contactId);
                }
                if ($anonymous !== []) {
                    $q->orWhereIn('anonymous_id', $anonymous);
                }
            })
            ->orderByDesc('last_activity_at')->limit(5)->get(['items', 'last_activity_at'])->all());
    }

    /**
     * Afinidad nocturna guardada en `contacts.category_affinity` (Fase 4), si existe.
     *
     * @return array<string, float>
     */
    private function storedAffinity(string $contactId): array
    {
        $raw = DB::table('contacts')->where('id', $contactId)->value('category_affinity');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['category_id'])) {
                $out[(string) $value['category_id']] = (float) ($value['score'] ?? 1.0);
            } elseif (is_numeric($value)) {
                $out[(string) $key] = (float) $value;
            }
        }

        return $out;
    }
}
