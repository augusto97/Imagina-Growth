<?php

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Insights\Stats;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->now = CarbonImmutable::now($this->ws->timezone);
});

function insightsOf($test): \Illuminate\Support\Collection
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Insight::query()->get());
}

function addOrder($test, array $attrs): string
{
    $id = strtoupper((string) Str::ulid());
    DB::table('orders')->insert($attrs + ['id' => $id, 'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => Str::random(10),
        'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => 100000, 'total_ws' => 100000, 'created_at' => now()->subDays(3)]);

    return $id;
}

it('la prueba z de proporciones detecta caídas reales', function () {
    expect(Stats::proportionDrop(300, 1000, 150, 1000)['p'])->toBeLessThan(0.001)
        ->and(Stats::proportionDrop(30, 100, 28, 100)['p'])->toBeGreaterThan(0.05)
        ->and(Stats::normalCdf(1.96))->toEqualWithDelta(0.975, 0.0005);
});

it('#1 detecta la caída de conversión del checkout y la cruza con un cambio del sitio', function () {
    $rows = [];
    $mk = function (CarbonImmutable $at, bool $bought, string $device) use (&$rows): void {
        $rows[] = ['workspace_id' => $this->ws->ch_id, 'site_id' => $this->site->ch_id, 'session_id' => Str::random(12), 'anonymous_id' => Str::random(8),
            'contact_id' => '', 'started_at' => $at->utc()->format('Y-m-d H:i:s'), 'ended_at' => $at->utc()->format('Y-m-d H:i:s'), 'pageviews' => 3,
            'channel' => 'direct', 'device_type' => $device, 'browser' => 'Chrome', 'viewed_product' => 1, 'added_to_cart' => 1, 'started_checkout' => 1,
            'purchased' => $bought ? 1 : 0, 'revenue' => '0'];
    };
    $start = $this->now->startOfDay();
    for ($i = 0; $i < 400; $i++) {   // base: 4 semanas, 50 % compra
        $mk($start->subDays(8 + $i % 27)->addHours(10), $i % 2 === 0, $i % 3 === 0 ? 'desktop' : 'mobile');
    }
    for ($i = 0; $i < 120; $i++) {   // última semana: 20 % compra
        $mk($start->subDays(1 + $i % 6)->addHours(10), $i % 5 === 0, $i % 3 === 0 ? 'desktop' : 'mobile');
    }
    app(Ch::class)->insert('sessions', $rows);
    app(Ch::class)->insert('events', [[
        'workspace_id' => $this->ws->ch_id, 'site_id' => $this->site->ch_id, 'event_id' => (string) Str::uuid(), 'event' => 'site.changed',
        'occurred_at' => $start->subDays(6)->utc()->format('Y-m-d H:i:s'), 'received_at' => now()->utc()->format('Y-m-d H:i:s'), 'source_type' => 'server',
        'properties' => (object) ['change' => 'plugin_updated', 'name' => 'woo-pagos', 'to_version' => '3.1.0'], 'revenue' => '0',
    ]]);
    addOrder($this, ['total_ws' => 200000]);

    app(InsightEngine::class)->run($this->ws, $this->now);
    $insight = insightsOf($this)->firstWhere('detector_key', 'conversion_drop');
    expect($insight)->not->toBeNull()
        ->and($insight->facts['p_value'])->toBeLessThan(0.05)
        ->and($insight->facts['relative_drop'])->toBeGreaterThan(0.15)
        ->and($insight->facts['site_changes'][0]['name'])->toBe('woo-pagos')
        ->and((float) $insight->estimated_impact_ws)->toBeGreaterThan(0)
        ->and($insight->impact_assumptions)->not->toBeEmpty();
});

it('#2 detecta un método de pago que deja pedidos sin pagar', function () {
    for ($i = 0; $i < 60; $i++) {   // base: 90 % pagados
        addOrder($this, ['payment_method_id' => 'pse', 'payment_method_title' => 'PSE', 'is_paid' => $i % 10 !== 0, 'status' => $i % 10 !== 0 ? 'completed' : 'failed', 'created_at' => now()->subDays(40 + $i)]);
    }
    for ($i = 0; $i < 30; $i++) {   // reciente: 60 % sin pagar
        addOrder($this, ['payment_method_id' => 'pse', 'payment_method_title' => 'PSE', 'is_paid' => $i % 5 < 2, 'status' => $i % 5 < 2 ? 'completed' : 'pending', 'created_at' => now()->subDays(3 + $i % 12)]);
    }
    app(InsightEngine::class)->run($this->ws, $this->now);
    $insight = insightsOf($this)->firstWhere('detector_key', 'payment_method');
    expect($insight)->not->toBeNull()->and($insight->facts['payment_method'])->toBe('pse')->and($insight->severity)->toBe('critical');
});

it('#6 avisa de productos estrella con menos de 14 días de inventario', function () {
    DB::table('products')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => '88',
        'type' => 'simple', 'name' => 'Vinilo Pintuco', 'price' => 150000, 'stock_status' => 'instock', 'stock_qty' => 5, 'status' => 'publish', 'content_hash' => 'x']);
    for ($i = 0; $i < 20; $i++) {
        $order = addOrder($this, ['created_at' => now()->subDays($i + 1)]);
        DB::table('order_items')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'order_id' => $order, 'external_product_id' => '88',
            'name' => 'Vinilo Pintuco', 'qty' => 1, 'unit_price' => 150000, 'line_total' => 150000]);
    }
    app(InsightEngine::class)->run($this->ws, $this->now);
    $insight = insightsOf($this)->firstWhere('detector_key', 'low_stock_stars');
    expect($insight)->not->toBeNull()->and($insight->facts['products'][0]['days_left'])->toBeLessThan(14);
});

it('#8 y la regla de 30 días: un descartado no vuelve salvo que empeore más de 50 %', function () {
    $mk = fn (int $n) => collect(range(1, $n))->each(fn () => DB::table('contacts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id,
        'lifecycle_stage' => 'customer', 'rfm_segment' => 'at_risk', 'revenue_total_ws' => 500000, 'aov_ws' => 100000, 'created_at' => now(), 'updated_at' => now()]));
    $mk(10);
    app(InsightEngine::class)->run($this->ws, $this->now);
    $first = insightsOf($this)->firstWhere('detector_key', 'valuable_at_risk');
    expect((float) $first->estimated_impact_ws)->toEqualWithDelta(180000.0, 0.01);

    $this->actingAs($this->owner)->patchJson("/api/w/{$this->ws->publicId()}/insights/{$first->publicId()}", ['status' => 'dismissed'])->assertOk();
    app(InsightEngine::class)->run($this->ws, $this->now);
    expect(insightsOf($this)->where('detector_key', 'valuable_at_risk')->count())->toBe(1);

    $mk(10);   // el doble de clientes en riesgo: empeora > 50 %
    app(InsightEngine::class)->run($this->ws, $this->now);
    expect(insightsOf($this)->where('detector_key', 'valuable_at_risk')->where('status', 'new')->count())->toBe(1);
});

it('#20 detecta el desfase de datos con la conciliación del sitio', function () {
    addOrder($this, ['status' => 'completed', 'created_at' => now()->subDays(10)]);
    $this->site->forceFill(['backfill_state' => ['status' => 'completed', 'from' => now()->subYear()->toDateString(), 'reconciliation' => [
        'currency' => 'COP', 'generated_at' => now()->toIso8601String(), 'orders_by_status' => ['completed' => ['count' => 50, 'total' => '5000000.00']]]]])->save();
    app(InsightEngine::class)->run($this->ws, $this->now);
    $insight = insightsOf($this)->firstWhere('detector_key', 'data_drift');
    expect($insight)->not->toBeNull()->and($insight->severity)->toBe('critical')->and($insight->facts['hub_count'])->toBe(1);
});

it('expone los hallazgos por API con permisos', function () {
    DB::table('contacts')->insert(array_map(fn () => ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'lifecycle_stage' => 'customer',
        'rfm_segment' => 'cant_lose', 'revenue_total_ws' => 1, 'aov_ws' => 1, 'created_at' => now(), 'updated_at' => now()], range(1, 6)));
    app(InsightEngine::class)->run($this->ws, $this->now);
    $url = "/api/w/{$this->ws->publicId()}/insights";
    $list = $this->actingAs($this->owner)->getJson($url)->assertOk()->json('data');
    expect($list)->toHaveCount(1);
    $this->actingAs($this->owner)->getJson("{$url}/{$list[0]['id']}")->assertOk()->assertJsonPath('status', 'seen');
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($url)->assertOk();
    $this->actingAs($viewer)->patchJson("{$url}/{$list[0]['id']}", ['status' => 'dismissed'])->assertForbidden();
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->patchJson("{$url}/{$list[0]['id']}", ['status' => 'actioned'])->assertOk()->assertJsonPath('status', 'actioned');
    expect(DB::table('audit_logs')->where('action', 'insight.actioned')->exists())->toBeTrue();
});
