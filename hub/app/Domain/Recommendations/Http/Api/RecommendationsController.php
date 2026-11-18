<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Http\Api;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Commerce\Data\RecommendationContext;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Recommendations\Engine\RecommendationEngine;
use App\Domain\Recommendations\Jobs\BuildRecommendationData;
use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recomendaciones en el panel (M16): los bloques y su estado, la vista previa
 * por tipo con un producto o contacto real y el explorador de reglas de
 * «comprados juntos». Ver: viewDashboards. Cambiar: manageMarketing.
 */
final class RecommendationsController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/onsite-commerce/recommendations?site_id= */
    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $sites = $this->sites();
        $site = $this->site($request, $sites);
        $ws = $this->current->id();
        $stats = null;
        if ($site !== null) {
            $base = DB::table('products')->where('workspace_id', $ws)->where('site_id', $site->id)->whereNull('deleted_at')->whereNull('parent_external_id')->where('status', 'publish');
            $rules = DB::table('product_associations')->where('workspace_id', $ws)->where('site_id', $site->id);
            $stats = [
                'products' => (clone $base)->count(),
                'in_stock' => (clone $base)->where(fn ($q) => $q->whereNull('stock_status')->orWhereIn('stock_status', ['instock', 'onbackorder']))->count(),
                'with_sales_90d' => (clone $base)->where('purchases_90d', '>', 0)->count(),
                'trending' => (clone $base)->where('purchases_7d', '>=', (int) config('igr-commerce.recommendations.trending_min_units', 2))->count(),
                'rules' => (clone $rules)->count(),
                'products_with_rules' => (clone $rules)->distinct()->count('product_id'),
                'stats_computed_at' => self::iso((clone $base)->max('stats_computed_at')),
                'rules_computed_at' => self::iso((clone $rules)->max('computed_at')),
            ];
        }
        $settings = CommerceSettings::current()->recommendationSettings();

        return response()->json([
            'sites' => $sites->map(fn (Site $s) => ['id' => $s->publicId(), 'name' => $s->name, 'url' => $s->url])->values(),
            'site_id' => $site?->publicId(),
            'settings' => $settings,
            'kinds' => array_map(fn (string $kind) => [
                'kind' => $kind,
                'enabled' => $settings['kinds'][$kind],
                'personal' => in_array($kind, ['para_ti', 'recently_viewed', 'complete_cart'], true),
                'needs' => match ($kind) {
                    'bought_together' => 'product',
                    'best_sellers_category' => 'category',
                    'complete_cart' => 'cart',
                    'para_ti', 'recently_viewed' => 'contact',
                    default => null,
                },
                'shortcode' => '[igr_reco type="'.$kind.'"]',
            ], RecommendationService::KINDS),
            'stats' => $stats,
            'config' => [
                'min_pair_orders' => (int) config('igr-commerce.recommendations.associations.min_pair_orders'),
                'window_days' => (int) config('igr-commerce.recommendations.associations.window_days'),
                'best_sellers_days' => (int) config('igr-commerce.recommendations.best_sellers_days'),
            ],
        ]);
    }

    /** PATCH /api/w/{ws}/onsite-commerce/recommendations/settings */
    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'kinds' => ['sometimes', 'array'],
            'kinds.*' => ['boolean'],
        ]);
        foreach (array_keys((array) ($data['kinds'] ?? [])) as $kind) {
            if (! in_array($kind, RecommendationService::KINDS, true)) {
                throw ValidationException::withMessages(['kinds' => __('Tipo de recomendación desconocido: :kind', ['kind' => $kind])]);
            }
        }
        $model = CommerceSettings::current();
        $current = $model->recommendationSettings();
        $model->recommendations = [
            'enabled' => (bool) ($data['enabled'] ?? $current['enabled']),
            'kinds' => array_merge($current['kinds'], array_map(fn ($v) => (bool) $v, (array) ($data['kinds'] ?? []))),
        ];
        $model->save();
        $this->audit->handle('onsite_commerce.recommendations_updated', $model, $model->recommendations, $this->current->id());

        return response()->json(['settings' => $model->recommendationSettings()]);
    }

    /** GET /api/w/{ws}/onsite-commerce/recommendations/preview?kind=&site_id=&product_id=&category_id=&contact_id=&cart=&limit= */
    public function preview(Request $request, RecommendationService $service): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate([
            'kind' => ['required', Rule::in(RecommendationService::KINDS)],
            'site_id' => ['nullable', 'string', 'max:40'],
            'product_id' => ['nullable', 'string', 'max:64'],
            'category_id' => ['nullable', 'string', 'max:64'],
            'contact_id' => ['nullable', 'string', 'max:40'],
            'cart' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $site = $this->site($request, $this->sites());
        if ($site === null) {
            throw ValidationException::withMessages(['site_id' => __('Conecta un sitio para ver recomendaciones.')]);
        }
        $contact = null;
        if (! empty($data['contact_id'])) {
            Gate::authorize('ws.viewAllContacts');
            $contact = Contact::findByPublicId($data['contact_id']);
            if ($contact === null) {
                throw ValidationException::withMessages(['contact_id' => __('Ese contacto no existe.')]);
            }
        }
        $cart = array_values(array_filter(array_map('trim', explode(',', (string) ($data['cart'] ?? ''))), fn ($v) => $v !== ''));
        $context = new RecommendationContext(
            siteId: $site->id,
            contactId: $contact?->id,
            productId: $data['product_id'] ?? null,
            categoryId: $data['category_id'] ?? null,
            cartProductIds: array_slice($cart, 0, 30),
        );
        $limit = (int) ($data['limit'] ?? 4);
        $result = $service instanceof RecommendationEngine
            ? $service->recommendWithSource($data['kind'], $context, $limit)
            : ['products' => $service->recommend($data['kind'], $context, $limit), 'fallback' => false];

        return response()->json(['kind' => $data['kind']] + $result);
    }

    /** GET /api/w/{ws}/onsite-commerce/recommendations/associations?site_id=&product_id=&page= */
    public function associations(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate(['product_id' => ['nullable', 'string', 'max:64'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        $site = $this->site($request, $this->sites());
        if ($site === null) {
            return response()->json(['data' => [], 'page' => 1, 'per_page' => 25, 'total' => 0]);
        }
        $ws = $this->current->id();
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $query = DB::table('product_associations as a')
            ->leftJoin('products as p', fn ($j) => $j->on('p.site_id', '=', 'a.site_id')->on('p.external_id', '=', 'a.product_id'))
            ->leftJoin('products as r', fn ($j) => $j->on('r.site_id', '=', 'a.site_id')->on('r.external_id', '=', 'a.related_product_id'))
            ->where('a.workspace_id', $ws)->where('a.site_id', $site->id)
            ->when(! empty($data['product_id']), fn ($q) => $q->where('a.product_id', $data['product_id']));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('a.lift')->orderByDesc('a.pair_orders')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['a.product_id', 'p.name as product_name', 'p.image_url as product_image', 'a.related_product_id', 'r.name as related_name', 'r.image_url as related_image',
                'r.stock_status as related_stock', 'a.pair_orders', 'a.support', 'a.confidence', 'a.lift', 'a.computed_at']);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'product' => ['id' => (string) $r->product_id, 'name' => $r->product_name, 'image' => $r->product_image],
                'related' => ['id' => (string) $r->related_product_id, 'name' => $r->related_name, 'image' => $r->related_image, 'in_stock' => in_array($r->related_stock, [null, 'instock', 'onbackorder'], true)],
                'pair_orders' => (int) $r->pair_orders,
                'support' => (float) $r->support,
                'confidence' => (float) $r->confidence,
                'lift' => (float) $r->lift,
            ])->values(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
    }

    /** GET /api/w/{ws}/onsite-commerce/products?site_id=&q= : buscador para la vista previa y los avisos. */
    public function products(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $q = trim((string) $request->query('q', ''));
        $site = $this->site($request, $this->sites());
        if ($site === null) {
            return response()->json(['data' => []]);
        }
        $rows = DB::table('products')->where('workspace_id', $this->current->id())->where('site_id', $site->id)
            ->whereNull('deleted_at')->whereNull('parent_external_id')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('external_id', $q)->orWhere('sku', $q)))
            ->orderByDesc('purchases_90d')->orderBy('name')->limit(20)
            ->get(['external_id', 'name', 'image_url', 'price', 'stock_status', 'purchases_90d', 'category_ids']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => (string) $r->external_id, 'name' => $r->name, 'image' => $r->image_url, 'price' => number_format((float) $r->price, 2, '.', ''),
            'in_stock' => in_array($r->stock_status, [null, 'instock', 'onbackorder'], true), 'sold_90d' => (int) $r->purchases_90d,
            'category_ids' => array_values(array_map('strval', (array) json_decode((string) $r->category_ids, true))),
        ])->values()]);
    }

    /** POST /api/w/{ws}/onsite-commerce/recommendations/rebuild */
    public function rebuild(): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        BuildRecommendationData::dispatch($this->current->id());

        return response()->json(['queued' => true], 202);
    }

    /** @return Collection<int, Site> */
    private function sites(): Collection
    {
        return Site::query()->where('platform', 'woocommerce')->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->get();
    }

    /** @param Collection<int, Site> $sites */
    private function site(Request $request, Collection $sites): ?Site
    {
        $wanted = $request->query('site_id', $request->input('site_id'));
        if (is_string($wanted) && $wanted !== '') {
            return $sites->first(fn (Site $s) => $s->publicId() === $wanted || $s->id === $wanted);
        }

        return $sites->first();
    }

    private static function iso(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
