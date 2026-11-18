<?php

declare(strict_types=1);

namespace App\Domain\Segments\Http;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Flows\Models\Flow;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Segments\Definition\Fields;
use App\Domain\Segments\Library\SegmentLibrary;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'values' => $this->values($ws),
            'segments' => DB::table('segments')->where('workspace_id', $ws)->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => ['id' => 'sg_'.$s->id, 'name' => $s->name])->values(),
            // Mensajería (Fase 2) y fidelización (Fase 3).
            'message_actions' => array_keys(Fields::MESSAGE_ACTIONS),
            'message_channels' => Fields::MESSAGE_CHANNELS,
            'engagement_days' => Fields::ENGAGEMENT_DAYS,
            'flows' => Flow::query()->where('status', '!=', Flow::ARCHIVED)->orderBy('name')->limit(200)->get(['id', 'name'])
                ->map(fn (Flow $f) => ['id' => $f->publicId(), 'name' => (string) $f->name])->values(),
            'campaigns' => Campaign::query()->orderByDesc('created_at')->limit(200)->get(['id', 'name'])
                ->map(fn (Campaign $c) => ['id' => $c->publicId(), 'name' => (string) $c->name])->values(),
            'lead_fields' => collect(Fields::LEAD_FIELDS)->map(fn (array $def, string $key) => ['key' => $key, 'type' => $def['type'], 'operators' => $def['operators'], 'values' => $def['values'] ?? null])->values(),
            'service_fields' => collect(Fields::SERVICE_FIELDS)->map(fn (array $def, string $key) => ['key' => $key, 'type' => $def['type'], 'operators' => $def['operators'], 'values' => $def['values'] ?? null])->values(),
            'loyalty_fields' => collect(Fields::LOYALTY_FIELDS)->map(fn (array $def, string $key) => ['key' => $key, 'type' => $def['type'], 'operators' => $def['operators'], 'values' => null])->values(),
            'loyalty_tiers' => LoyaltyTier::query()->orderBy('rank')->get(['id', 'name', 'rank'])
                ->map(fn (LoyaltyTier $t) => ['id' => $t->publicId(), 'name' => (string) $t->name, 'rank' => (int) $t->rank])->values(),
        ]);
    }

    /**
     * Valores frecuentes para sugerir (ciudades, departamentos, medios de pago…).
     * Recorren toda la tabla de contactos, así que se guardan 10 minutos.
     *
     * @return array<string, mixed>
     */
    private function values(string $ws): array
    {
        return Cache::remember(RedisKey::for($ws, 'segments:option-values'), 600, function () use ($ws): array {
            // Una sola pasada por contacts para las cuatro listas.
            $rows = DB::table('contacts')->where('workspace_id', $ws)->whereNull('merged_into_id')
                ->groupBy('city', 'region_code', 'country', 'hdyhau')
                ->selectRaw('city, region_code, country, hdyhau, COUNT(*) AS n')
                ->limit(20000)->get();
            $top = function (string $column, int $limit) use ($rows): array {
                $counts = [];
                foreach ($rows as $row) {
                    $value = (string) ($row->{$column} ?? '');
                    if ($value !== '') {
                        $counts[$value] = ($counts[$value] ?? 0) + (int) $row->n;
                    }
                }
                arsort($counts);

                return array_map('strval', array_slice(array_keys($counts), 0, $limit));
            };

            return [
                'city' => $top('city', 100),
                'region_code' => $top('region_code', 60),
                'country' => $top('country', 30),
                'hdyhau' => $top('hdyhau', 50),
                'payment_method' => DB::table('orders')->where('workspace_id', $ws)->whereNotNull('payment_method_id')
                    ->groupBy('payment_method_id')->orderByRaw('COUNT(*) DESC')->limit(30)
                    ->selectRaw('payment_method_id AS id, MAX(payment_method_title) AS title')->get()
                    ->map(fn ($r) => ['id' => (string) $r->id, 'title' => $r->title === null ? null : (string) $r->title])->all(),
                'brand' => DB::table('products')->where('workspace_id', $ws)->whereNotNull('brand')->where('brand', '<>', '')
                    ->groupBy('brand')->orderByRaw('COUNT(*) DESC')->limit(200)->pluck('brand')->map(fn ($v) => (string) $v)->all(),
            ];
        });
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
