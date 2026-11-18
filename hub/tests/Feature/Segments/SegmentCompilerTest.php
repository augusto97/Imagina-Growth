<?php

use App\Analytics\Ch;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;
use App\Domain\Segments\Definition\DefinitionValidator;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * El compilador contra MariaDB y ClickHouse reales: cada tipo de condición
 * del AST (hub/CLAUDE.md §6) y sus combinaciones.
 */

function segContact(Workspace $ws, array $attrs = []): Contact
{
    return app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create($attrs + [
        'workspace_id' => $ws->id, 'lifecycle_stage' => 'lead', 'first_seen_at' => now()->subYear(), 'last_seen_at' => now(),
    ]));
}

function segOrder(Workspace $ws, string $siteId, Contact $c, array $o = [], array $item = []): void
{
    $id = strtoupper((string) Str::ulid());
    DB::table('orders')->insert($o + [
        'id' => $id, 'workspace_id' => $ws->id, 'site_id' => $siteId, 'external_id' => (string) random_int(1, 999999999),
        'contact_id' => $c->id, 'status' => 'completed', 'is_paid' => true, 'paid_at' => now()->subDays(10), 'currency' => 'COP',
        'total' => 100000, 'total_ws' => 100000, 'payment_method_id' => 'wompi', 'coupon_codes' => '[]', 'items_count' => 1,
        'created_at' => now()->subDays(10), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert($item + [
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'order_id' => $id, 'external_product_id' => '88',
        'name' => 'Pintura', 'category_ids' => '["12"]', 'brand' => 'Pintuco', 'qty' => 1, 'unit_price' => 100000, 'line_total' => 100000,
    ]);
}

function segEvents(Workspace $ws, array $rows): void
{
    $now = CarbonImmutable::now('UTC');
    app(Ch::class)->insert('events', array_map(fn (array $r) => [
        'workspace_id' => $ws->ch_id, 'site_id' => 1, 'event_id' => (string) Str::uuid(), 'event' => $r['event'],
        'occurred_at' => ($r['at'] ?? $now->subHour())->format('Y-m-d H:i:s.v'), 'received_at' => $now->format('Y-m-d H:i:s.v'),
        'source_type' => 'browser', 'anonymous_id' => $r['anon'] ?? '', 'session_id' => $r['session'] ?? 's1',
        'contact_id' => $r['contact'] ?? '', 'product_id' => $r['product'] ?? '', 'properties' => $r['properties'] ?? new stdClass,
    ], $rows));
}

/** @return list<string> IDs internos de los contactos que cumplen */
function segMatch(Workspace $ws, array $definition, ?string $contactId = null): array
{
    return app(CurrentWorkspace::class)->run($ws, function () use ($ws, $definition, $contactId) {
        $ast = app(DefinitionValidator::class)->validate($definition);
        $ctx = $contactId ? CompileContext::forContact($ws, $contactId) : new CompileContext($ws);
        try {
            $ids = app(SegmentCompiler::class)->query($ast, $ctx)->pluck('c.id')->map(fn ($i) => (string) $i)->sort()->values()->all();
        } finally {
            $ctx->cleanup();
        }

        return $ids;
    });
}

function segAnd(array ...$conditions): array
{
    return ['op' => 'and', 'conditions' => $conditions];
}

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
    $ws = $this->ws;
    $this->ana = segContact($ws, ['city' => 'Manizales', 'region_code' => 'CO-CAL', 'tags' => ['mayorista'], 'birthday' => now()->setDay(5)->toDateString(),
        'orders_count' => 3, 'revenue_total_ws' => 300000, 'coupon_orders_ratio' => 0, 'last_order_at' => now()->subDays(10),
        'rfm_segment' => 'champions', 'rfm_r' => 5, 'email_bidx' => BlindIndex::email($ws->id, 'ana@x.co'), 'phone_bidx' => BlindIndex::phone($ws->id, '+573001112233'),
        'custom_attributes' => ['mascotas' => 2, 'talla' => 'M']]);
    $this->luis = segContact($ws, ['city' => 'Pereira', 'orders_count' => 1, 'revenue_total_ws' => 100000, 'coupon_orders_ratio' => 1,
        'last_order_at' => now()->subDays(90), 'rfm_segment' => 'hibernating', 'rfm_r' => 1, 'phone_bidx' => BlindIndex::phone($ws->id, '+573004445566')]);
    $this->sin = segContact($ws, ['city' => null, 'orders_count' => 0]);
    foreach ([1, 2, 3] as $i) {
        segOrder($ws, $this->site->id, $this->ana, ['paid_at' => now()->subDays(10 * $i), 'coupon_codes' => $i === 1 ? '["BIENVENIDA10"]' : '[]'],
            $i === 3 ? ['external_product_id' => '91', 'category_ids' => '["15"]', 'brand' => 'Sika'] : []);
    }
    segOrder($ws, $this->site->id, $this->luis, ['paid_at' => now()->subDays(90), 'payment_method_id' => 'cod'], ['external_product_id' => '77']);
    segOrder($ws, $this->site->id, $this->luis, ['is_paid' => false, 'status' => 'cancelled', 'paid_at' => null]);
});

it('atributos: lista, distinto (incluye vacíos), contiene, etiquetas, cumpleaños y personalizados', function () {
    expect(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'city', 'operator' => 'in', 'value' => ['manizales', 'Bogotá']])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'city', 'operator' => 'neq', 'value' => 'Manizales'])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'city', 'operator' => 'contains', 'value' => 'reir'])))->toBe([$this->luis->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'city', 'operator' => 'is_not_set'])))->toBe([$this->sin->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'tags', 'operator' => 'contains_any', 'value' => ['mayorista', 'otro']])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'birthday', 'operator' => 'this_month'])))->toBe([$this->ana->id]);

    DB::table('custom_attribute_defs')->insert([
        ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'key' => 'mascotas', 'label' => 'Mascotas', 'type' => 'number', 'options' => null, 'is_sensitive' => false, 'created_at' => now(), 'updated_at' => now()],
        ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'key' => 'talla', 'label' => 'Talla', 'type' => 'enum', 'options' => '["S","M","L"]', 'is_sensitive' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    expect(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'custom.mascotas', 'operator' => 'gte', 'value' => 2])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'custom.talla', 'operator' => 'in', 'value' => ['M', 'L']])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'attribute', 'field' => 'custom.talla', 'operator' => 'is_not_set'])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id]);
});

it('compras: agregados, ventanas, producto, categoría, marca, cupón y medio de pago', function () {
    expect(segMatch($this->ws, segAnd(['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 2])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'field' => 'last_order_at', 'operator' => 'more_than_days_ago', 'value' => 60])))->toBe([$this->luis->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'field' => 'total_spent', 'operator' => 'gte', 'value' => 150000, 'window' => ['kind' => 'last_days', 'days' => 25]])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'eq', 'value' => 0, 'window' => ['kind' => 'last_days', 'days' => 30]])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'product', 'ids' => ['88'], 'operator' => 'count_gte', 'value' => 2])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'category', 'ids' => ['15'], 'operator' => 'ever'])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'category', 'ids' => ['15'], 'operator' => 'never'])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'brand', 'ids' => ['Sika'], 'operator' => 'count_eq', 'value' => 1])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'coupon', 'ids' => [], 'operator' => 'ever'])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'payment_method', 'ids' => ['cod'], 'operator' => 'ever'])))->toBe([$this->luis->id])
        // Los pedidos sin pagar no cuentan.
        ->and(segMatch($this->ws, segAnd(['type' => 'purchase', 'scope' => 'any', 'operator' => 'count_lte', 'value' => 1])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id]);
});

it('predicciones y consentimiento (sin los suprimidos)', function () {
    $consent = fn (Contact $c, string $channel) => DB::table('contact_consents')->insert([
        'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $c->id, 'channel' => $channel, 'purpose' => 'marketing',
        'status' => 'granted', 'source' => 'checkout', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $consent($this->ana, 'whatsapp');
    $consent($this->luis, 'whatsapp');
    $consent($this->ana, 'email');
    DB::table('suppressions')->insert(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'value_bidx' => BlindIndex::phone($this->ws->id, '+573004445566'), 'reason' => 'unsubscribe']);

    expect(segMatch($this->ws, segAnd(['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'in', 'value' => ['champions', 'loyal']])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'prediction', 'field' => 'rfm_r', 'operator' => 'lte', 'value' => 2])))->toBe([$this->luis->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'consent', 'channel' => 'whatsapp', 'status' => 'granted'])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'consent', 'channel' => 'whatsapp', 'status' => 'not_granted'])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'consent', 'channel' => 'email'])))->toBe([$this->ana->id]);
});

it('comportamiento en ClickHouse, con eventos anónimos resueltos por identity_map', function () {
    $now = CarbonImmutable::now('UTC');
    segEvents($this->ws, [
        ['event' => 'product.viewed', 'contact' => $this->ana->id, 'product' => '88', 'session' => 'a1'],
        ['event' => 'product.viewed', 'contact' => $this->ana->id, 'product' => '88', 'session' => 'a2'],
        ['event' => 'product.viewed', 'contact' => $this->ana->id, 'product' => '99', 'session' => 'a2'],
        // Luis navegó anónimo; luego se identificó.
        ['event' => 'product.viewed', 'anon' => 'anon-luis', 'product' => '88', 'session' => 'l1'],
        ['event' => 'product.viewed', 'anon' => 'anon-luis', 'product' => '88', 'session' => 'l1', 'at' => $now->subDays(40)],
        ['event' => 'search.performed', 'contact' => $this->luis->id, 'properties' => ['query' => 'Pintura azul', 'engine' => 'wc']],
    ]);
    app(Ch::class)->insert('identity_map', [['workspace_id' => $this->ws->ch_id, 'anonymous_id' => 'anon-luis', 'contact_id' => $this->luis->id, 'linked_at' => $now->format('Y-m-d H:i:s.v')]]);
    DB::table('contact_identities')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $this->luis->id, 'type' => 'anonymous_id', 'value' => 'anon-luis']);

    $viewed = fn (array $extra) => segAnd(['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'eq', 'value' => '88']]] + $extra);
    expect(segMatch($this->ws, $viewed(['operator' => 'count_gte', 'value' => 2])))->toEqualCanonicalizing([$this->ana->id, $this->luis->id])
        ->and(segMatch($this->ws, $viewed(['operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 7]])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, $viewed(['operator' => 'count_gte', 'value' => 2, 'measure' => 'sessions'])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, $viewed(['operator' => 'never'])))->toBe([$this->sin->id])
        ->and(segMatch($this->ws, $viewed(['operator' => 'count_lte', 'value' => 1, 'window' => ['kind' => 'last_days', 'days' => 7]])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'event', 'event' => 'search.performed', 'filters' => [['prop' => 'query', 'operator' => 'contains', 'value' => 'AZUL']], 'operator' => 'ever'])))->toBe([$this->luis->id])
        // Vio el 88 sin comprarlo (Luis no compró el 88; Ana sí).
        ->and(segMatch($this->ws, segAnd(
            ['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'in', 'value' => ['88']]], 'operator' => 'ever'],
            ['type' => 'purchase', 'scope' => 'product', 'ids' => ['88'], 'operator' => 'never'],
        )))->toBe([$this->luis->id])
        // Evaluación de un solo contacto (tiempo real): solo sus eventos, también los anónimos.
        ->and(segMatch($this->ws, $viewed(['operator' => 'count_gte', 'value' => 2]), $this->luis->id))->toBe([$this->luis->id])
        ->and(segMatch($this->ws, $viewed(['operator' => 'count_gte', 'value' => 3]), $this->luis->id))->toBe([]);
});

it('carrito abandonado: carrito sin pedido posterior y con más de 45 minutos', function () {
    $now = CarbonImmutable::now('UTC');
    segEvents($this->ws, [
        ['event' => 'cart.item_added', 'contact' => $this->ana->id, 'at' => $now->subHours(5)],
        ['event' => 'order.created', 'contact' => $this->ana->id, 'at' => $now->subHours(4)],
        ['event' => 'checkout.started', 'contact' => $this->luis->id, 'at' => $now->subHours(3)],
        ['event' => 'cart.item_added', 'contact' => $this->sin->id, 'at' => $now->subMinutes(10)],
    ]);
    expect(segMatch($this->ws, segAnd(['type' => 'cart', 'operator' => 'abandoned', 'window' => ['kind' => 'last_days', 'days' => 7]])))->toBe([$this->luis->id]);
});

it('combina grupos Y/O anidados, negación y referencias a otros segmentos', function () {
    $nested = ['op' => 'or', 'conditions' => [
        ['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => 'Pereira'],
        ['op' => 'and', 'conditions' => [
            ['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'eq', 'value' => 'champions'],
            ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 3],
        ]],
    ]];
    expect(segMatch($this->ws, $nested))->toEqualCanonicalizing([$this->ana->id, $this->luis->id])
        ->and(segMatch($this->ws, ['op' => 'and', 'conditions' => [$nested + ['negate' => true]]]))->toBe([$this->sin->id]);

    $segment = app(CurrentWorkspace::class)->run($this->ws, fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Fijo', 'kind' => 'static']));
    DB::table('segment_memberships')->insert(['segment_id' => $segment->id, 'contact_id' => $this->luis->id, 'workspace_id' => $this->ws->id, 'entered_at' => now()]);
    expect(segMatch($this->ws, segAnd(['type' => 'segment', 'segment_id' => $segment->publicId(), 'operator' => 'in'])))->toBe([$this->luis->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'segment', 'segment_id' => $segment->publicId(), 'operator' => 'not_in'])))->toEqualCanonicalizing([$this->ana->id, $this->sin->id]);
});

it('materializa en una tabla temporal los conjuntos grandes de ClickHouse', function () {
    $rows = [];
    for ($i = 0; $i < CompileContext::INLINE_LIMIT + 50; $i++) {
        $rows[] = ['event' => 'page.viewed', 'contact' => sprintf('01ZZZZ%020d', $i)];
    }
    $rows[] = ['event' => 'page.viewed', 'contact' => $this->ana->id];
    segEvents($this->ws, $rows);
    expect(segMatch($this->ws, segAnd(['type' => 'event', 'event' => 'page.viewed', 'operator' => 'ever'])))->toBe([$this->ana->id])
        ->and(segMatch($this->ws, segAnd(['type' => 'event', 'event' => 'page.viewed', 'operator' => 'never'])))->toEqualCanonicalizing([$this->luis->id, $this->sin->id]);
});
