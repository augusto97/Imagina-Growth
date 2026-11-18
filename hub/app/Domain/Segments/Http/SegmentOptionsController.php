<?php

declare(strict_types=1);

namespace App\Domain\Segments\Http;

use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Segments\Definition\Fields;
use App\Domain\Segments\Library\SegmentLibrary;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Opciones para los selectores del constructor de segmentos. */
final class SegmentOptionsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET segments/options */
    public function index(SchemaRegistry $schemas): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $ws = $this->current->id();
        $distinct = fn (string $table, string $column, int $limit = 100) => DB::table($table)->where('workspace_id', $ws)
            ->whereNotNull($column)->where($column, '<>', '')
            ->groupBy($column)->orderByRaw('COUNT(*) DESC')->limit($limit)->pluck($column)->map(fn ($v) => (string) $v)->values();

        $fieldsOf = fn (array $defs) => collect($defs)->map(fn (array $def, string $key) => [
            'key' => $key, 'type' => $def['type'], 'operators' => Fields::operatorsFor($def['type']), 'values' => $def['values'] ?? null,
        ])->values();

        $events = collect($schemas->knownEvents())->reject(fn (string $e) => str_starts_with($e, 'catalog.') || str_starts_with($e, 'site.'))->sort()->values();

        return response()->json([
            'attributes' => $fieldsOf(Fields::ATTRIBUTES),
            'purchase_fields' => $fieldsOf(Fields::PURCHASE_FIELDS),
            'predictions' => $fieldsOf(Fields::PREDICTIONS),
            'custom_attributes' => DB::table('custom_attribute_defs')->where('workspace_id', $ws)->where('is_sensitive', false)->orderBy('label')
                ->get(['key', 'label', 'type', 'options'])->map(fn ($d) => [
                    'key' => 'custom.'.$d->key, 'label' => $d->label, 'type' => Fields::customType((string) $d->type),
                    'operators' => Fields::operatorsFor(Fields::customType((string) $d->type)),
                    'values' => is_string($d->options) ? json_decode($d->options, true) : null,
                ])->values(),
            'events' => $events,
            'event_columns' => Fields::EVENT_COLUMNS,
            'rfm_segments' => collect(SegmentLibrary::RFM)->map(fn (array $v, string $k) => ['key' => $k, 'name' => $v[0]])->values(),
            'values' => [
                'city' => $distinct('contacts', 'city'),
                'region_code' => $distinct('contacts', 'region_code', 60),
                'country' => $distinct('contacts', 'country', 30),
                'payment_method' => DB::table('orders')->where('workspace_id', $ws)->whereNotNull('payment_method_id')
                    ->groupBy('payment_method_id')->orderByRaw('COUNT(*) DESC')->limit(30)
                    ->selectRaw('payment_method_id AS id, MAX(payment_method_title) AS title')->get(),
                'brand' => $distinct('products', 'brand', 200),
                'hdyhau' => $distinct('contacts', 'hdyhau', 50),
            ],
            'segments' => DB::table('segments')->where('workspace_id', $ws)->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => ['id' => 'sg_'.$s->id, 'name' => $s->name])->values(),
        ]);
    }

    /** GET segments/options/products?q=&ids[]= */
    public function products(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $q = trim((string) $request->query('q', ''));
        $ids = array_filter(array_map('strval', (array) $request->query('ids', [])));
        $rows = Product::query()
            ->whereNull('deleted_at')
            ->when($ids !== [], fn ($x) => $x->whereIn('external_id', $ids))
            ->when($ids === [] && $q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('sku', $q)->orWhere('external_id', $q)))
            ->orderBy('name')->limit(20)
            ->get(['external_id', 'name', 'sku', 'image_url', 'price']);

        return response()->json(['data' => $rows->map(fn ($p) => ['id' => (string) $p->external_id, 'name' => $p->getAttribute('name'), 'sku' => $p->getAttribute('sku'), 'price' => (string) $p->getAttribute('price')])->values()]);
    }

    /** GET segments/options/categories?q=&ids[]= */
    public function categories(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewAllContacts');
        $q = trim((string) $request->query('q', ''));
        $ids = array_filter(array_map('strval', (array) $request->query('ids', [])));
        $rows = ProductCategory::query()
            ->whereNull('deleted_at')
            ->when($ids !== [], fn ($x) => $x->whereIn('external_id', $ids))
            ->when($ids === [] && $q !== '', fn ($x) => $x->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')->limit(50)
            ->get(['external_id', 'name']);

        return response()->json(['data' => $rows->map(fn ($c) => ['id' => (string) $c->external_id, 'name' => $c->getAttribute('name')])->values()]);
    }
}
