<?php

require_once __DIR__.'/InsightsHelpers.php';

use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Insights\Queries\TopInsights;
use App\Domain\Intelligence\Insights\Stats;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => insightsSetUp($this));

function insightsAtRisk($test, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        insightsContact($test, ['rfm_segment' => 'at_risk', 'revenue_total_ws' => 500000, 'aov_ws' => 100000]);
    }
}

it('la prueba z de proporciones detecta caídas reales', function () {
    expect(Stats::proportionDrop(300, 1000, 150, 1000)['p'])->toBeLessThan(0.001)
        ->and(Stats::proportionDrop(30, 100, 28, 100)['p'])->toBeGreaterThan(0.05)
        ->and(Stats::normalCdf(1.96))->toEqualWithDelta(0.975, 0.0005);
});

it('escribe la fórmula del impacto con sus supuestos en español', function () {
    $impact = Impact::of(new Term('clientes', 37, 'count'), new Term('de reactivación de referencia', 0.18, 'rate', 'reactivation_rate'), new Term('de ticket', 185000, 'money'));
    expect($impact->total())->toBe(1232100.0)
        ->and($impact->text('COP'))->toBe('37 clientes × 18 % de reactivación de referencia × $185.000 de ticket = $1.232.100')
        ->and($impact->with(['reactivation_rate' => 0.1])->total())->toBe(684500.0)
        ->and(Impact::fromArray($impact->toArray())->total())->toBe(1232100.0);
});

it('#1 detecta la caída de conversión del checkout y la cruza con un cambio del sitio', function () {
    $rows = [];
    $start = $this->now->startOfDay();
    for ($i = 0; $i < 400; $i++) {   // base: 4 semanas, 50 % compra
        $rows[] = insightsSession($this, $start->subDays(8 + $i % 27)->addHours(10), $i % 2 === 0, ['device_type' => $i % 3 === 0 ? 'desktop' : 'mobile', 'added_to_cart' => 1, 'started_checkout' => 1]);
    }
    for ($i = 0; $i < 120; $i++) {   // última semana: 20 % compra
        $rows[] = insightsSession($this, $start->subDays(1 + $i % 6)->addHours(10), $i % 5 === 0, ['device_type' => $i % 3 === 0 ? 'desktop' : 'mobile', 'added_to_cart' => 1, 'started_checkout' => 1]);
    }
    insightsCh('sessions', $rows);
    insightsCh('events', [insightsEvent($this, 'site.changed', $start->subDays(6), ['change' => 'plugin_updated', 'name' => 'woo-pagos', 'to_version' => '3.1.0'], ['source_type' => 'server'])]);
    insightsOrder($this, ['total_ws' => 200000]);

    insightsRun($this, ['conversion_drop']);
    $insight = insightsOf($this)->firstWhere('detector_key', 'conversion_drop');
    expect($insight)->not->toBeNull()
        ->and($insight->facts['p_value'])->toBeLessThan(0.05)
        ->and($insight->facts['relative_drop'])->toBeGreaterThan(0.15)
        ->and($insight->facts['site_changes'][0]['name'])->toBe('woo-pagos')
        ->and($insight->title)->toContain('woo-pagos')
        ->and((float) $insight->estimated_impact_ws)->toBeGreaterThan(0)
        ->and($insight->impact_assumptions['formula']['terms'][0]['unit'])->toBe('count');
});

it('#2 detecta un método de pago que deja pedidos sin pagar, con el supuesto de recuperación editable', function () {
    for ($i = 0; $i < 60; $i++) {   // base: 90 % pagados
        insightsOrder($this, ['payment_method_id' => 'pse', 'payment_method_title' => 'PSE', 'is_paid' => $i % 10 !== 0, 'status' => $i % 10 !== 0 ? 'completed' : 'failed', 'created_at' => now()->subDays(40 + $i)]);
    }
    for ($i = 0; $i < 30; $i++) {   // reciente: 60 % sin pagar
        insightsOrder($this, ['payment_method_id' => 'pse', 'payment_method_title' => 'PSE', 'is_paid' => $i % 5 < 2, 'status' => $i % 5 < 2 ? 'completed' : 'pending', 'created_at' => now()->subDays(3 + $i % 12)]);
    }
    insightsRun($this, ['payment_method']);
    $insight = insightsOf($this)->firstWhere('detector_key', 'payment_method');
    expect($insight)->not->toBeNull()->and($insight->facts['payment_method'])->toBe('pse')->and($insight->severity)->toBe('critical')
        ->and($insight->facts['payment_recovery_rate'])->toBe(0.3);
});

it('#2 sin un método con fallas de más no dice nada', function () {
    for ($i = 0; $i < 90; $i++) {
        insightsOrder($this, ['payment_method_id' => 'pse', 'is_paid' => $i % 10 !== 0, 'status' => 'completed', 'created_at' => now()->subDays(3 + $i)]);
    }
    insightsRun($this, ['payment_method']);
    expect(insightsOf($this)->where('detector_key', 'payment_method'))->toHaveCount(0);
});

it('#6 avisa de productos estrella con menos de 14 días de inventario', function () {
    insightsProduct($this, '88', 'Vinilo Pintuco', 150000, ['stock_qty' => 5]);
    for ($i = 0; $i < 20; $i++) {
        insightsItem($this, insightsOrder($this, ['created_at' => now()->subDays($i + 1)]), '88', 'Vinilo Pintuco', 150000);
    }
    insightsRun($this, ['low_stock_stars']);
    $insight = insightsOf($this)->firstWhere('detector_key', 'low_stock_stars');
    expect($insight)->not->toBeNull()->and($insight->facts['products'][0]['days_left'])->toBeLessThan(14);
});

it('#8 y la regla de 30 días: un descartado no vuelve salvo que empeore más de 50 %', function () {
    insightsAtRisk($this, 10);
    insightsRun($this, ['valuable_at_risk']);
    $first = insightsOf($this)->firstWhere('detector_key', 'valuable_at_risk');
    expect((float) $first->estimated_impact_ws)->toEqualWithDelta(180000.0, 0.01);

    $this->actingAs($this->owner)->patchJson("/api/w/{$this->ws->publicId()}/insights/{$first->publicId()}", ['status' => 'dismissed'])->assertOk();
    insightsRun($this, ['valuable_at_risk']);
    expect(insightsOf($this)->where('detector_key', 'valuable_at_risk')->count())->toBe(1);

    insightsAtRisk($this, 10);   // el doble de clientes en riesgo: empeora > 50 %
    insightsRun($this, ['valuable_at_risk']);
    expect(insightsOf($this)->where('detector_key', 'valuable_at_risk')->where('status', 'new')->count())->toBe(1);
});

it('#20 detecta el desfase de datos con la conciliación del sitio', function () {
    insightsOrder($this, ['status' => 'completed', 'created_at' => now()->subDays(10)]);
    $this->site->forceFill(['backfill_state' => ['status' => 'completed', 'from' => now()->subYear()->toDateString(), 'reconciliation' => [
        'currency' => 'COP', 'generated_at' => now()->toIso8601String(), 'orders_by_status' => ['completed' => ['count' => 50, 'total' => '5000000.00']]]]])->save();
    insightsRun($this, ['data_drift']);
    $insight = insightsOf($this)->firstWhere('detector_key', 'data_drift');
    expect($insight)->not->toBeNull()->and($insight->severity)->toBe('critical')->and($insight->facts['hub_count'])->toBe(1)->and($insight->facts['magnitude'])->toBeGreaterThan(0.9);
});

it('el supuesto editado recalcula al instante el impacto, la fórmula y el hecho; null vuelve al valor por defecto', function () {
    insightsAtRisk($this, 10);
    insightsRun($this, ['valuable_at_risk']);
    $url = "/api/w/{$this->ws->publicId()}/insights";
    $list = $this->actingAs($this->owner)->getJson($url)->assertOk()->json('data');
    expect($list[0]['formula']['text'])->toBe('10 clientes × 18 % de reactivación de referencia × $100.000 de ticket = $180.000')
        ->and($list[0]['category'])->toBe('customers')->and($list[0]['number'])->toBe(8);

    $res = $this->actingAs($this->owner)->putJson("{$url}/assumptions/reactivation_rate", ['value' => 0.1])->assertOk();
    expect($res->json('updated'))->toBe(1)->and(collect($res->json('assumptions'))->firstWhere('key', 'reactivation_rate')['edited'])->toBeTrue();
    $one = $this->actingAs($this->owner)->getJson("{$url}/{$list[0]['id']}")->assertOk();
    expect((float) $one->json('impact'))->toBe(100000.0)->and($one->json('formula.text'))->toContain('10 % de reactivación')
        ->and($one->json('facts.reactivation_rate'))->toEqual(0.1);

    // La próxima corrida usa el valor editado; null lo devuelve al de config.
    insightsRun($this, ['valuable_at_risk']);
    expect((float) insightsOf($this)->firstWhere('detector_key', 'valuable_at_risk')->estimated_impact_ws)->toBe(100000.0);
    $this->actingAs($this->owner)->putJson("{$url}/assumptions/reactivation_rate", ['value' => null])->assertOk();
    expect((float) insightsOf($this)->firstWhere('detector_key', 'valuable_at_risk')->estimated_impact_ws)->toBe(180000.0);

    $this->actingAs($this->owner)->putJson("{$url}/assumptions/reactivation_rate", ['value' => 5])->assertUnprocessable();
    $this->actingAs($this->owner)->putJson("{$url}/assumptions/inventado", ['value' => 0.1])->assertNotFound();
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->putJson("{$url}/assumptions/reactivation_rate", ['value' => 0.2])->assertForbidden();
});

it('«Hacer ahora»: crea la campaña con el segmento ya elegido y marca el hallazgo como atendido', function () {
    insightsAtRisk($this, 10);
    insightsRun($this, ['valuable_at_risk']);
    $insight = insightsOf($this)->firstWhere('detector_key', 'valuable_at_risk');
    $url = "/api/w/{$this->ws->publicId()}/insights/{$insight->publicId()}/act";
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->postJson($url)->assertForbidden();

    $res = $this->actingAs($this->owner)->postJson($url)->assertOk();
    expect($res->json('result'))->toBe('campaign_created')->and($res->json('to'))->toStartWith('campanas/cmp_')->and($res->json('insight.status'))->toBe('actioned');
    $campaign = DB::table('campaigns')->where('workspace_id', $this->ws->id)->first();
    $segment = DB::table('segments')->where('workspace_id', $this->ws->id)->where('system_key', 'rfm_at_risk')->value('id');
    expect(json_decode($campaign->audience, true)['segments_include'][0])->toBe(PrefixedId::encode('sg', $segment))
        ->and(DB::table('audit_logs')->where('action', 'insight.actioned')->exists())->toBeTrue();
});

it('«Hacer ahora»: activa el flujo de carrito abandonado de la biblioteca', function () {
    for ($i = 0; $i < 8; $i++) {
        DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => Str::random(20),
            'status' => 'abandoned', 'subtotal' => 200000, 'subtotal_ws' => 200000, 'currency' => 'COP', 'items_count' => 1, 'abandoned_at' => now()->subDays(2),
            'last_activity_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    }
    insightsRun($this, ['essential_flow_inactive']);
    $insight = insightsOf($this)->firstWhere('detector_key', 'essential_flow_inactive');
    expect((float) $insight->estimated_impact_ws)->toBe(80000.0);   // 8 × 5 % × $200.000

    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/insights/{$insight->publicId()}/act")->assertOk();
    expect($res->json('to'))->toStartWith('flujos/fl_')->and($res->json('result'))->toBeIn(['flow_activated', 'flow_draft']);
    expect(DB::table('flows')->where('workspace_id', $this->ws->id)->where('template_key', 'abandoned_cart')->exists())->toBeTrue();

    // Con el flujo creado, el detector ya no lo propone (si quedó activo) o solo enlaza.
    $again = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/insights/{$insight->publicId()}/act")->assertOk();
    expect($again->json('result'))->toBeIn(['already_active', 'exists']);
});

it('los semanales corren los lunes o si nunca corrieron; la vigencia vencida expira el hallazgo', function () {
    $tuesday = CarbonImmutable::parse('2026-11-17 03:00', $this->ws->timezone);
    insightsRun($this, null, $tuesday, false);
    $runs = InsightEngine::runs($this->ws);
    expect($runs['concentration']['status'])->toBe('ok')->and($runs['slow_pages']['status'])->toBe('skipped')
        ->and($runs['slow_pages']['reason'])->toContain('Web Vitals')->and($runs['leads_unanswered']['status'])->toBe('ok');

    // Un día después (miércoles) los semanales no corren otra vez.
    $at = $runs['concentration']['at'];
    insightsRun($this, null, $tuesday->addDay(), false);
    expect(InsightEngine::runs($this->ws)['concentration']['at'])->toBe($at);

    insightsAtRisk($this, 10);
    insightsRun($this, ['valuable_at_risk'], $tuesday);
    $insight = insightsOf($this)->firstWhere('detector_key', 'valuable_at_risk');
    expect($insight->valid_until->toDateString())->toBe($tuesday->addDays(7)->utc()->toDateString());
    insightsRun($this, ['payment_method'], $tuesday->addDays(8));
    expect($insight->refresh()->status)->toBe('expired');
});

it('expone los hallazgos por API con filtros, resumen, ajustes y permisos', function () {
    insightsAtRisk($this, 6);
    insightsRun($this, ['valuable_at_risk']);
    $url = "/api/w/{$this->ws->publicId()}/insights";
    $res = $this->actingAs($this->owner)->getJson($url)->assertOk();
    expect($res->json('data'))->toHaveCount(1)->and($res->json('meta.open'))->toBe(1)->and($res->json('meta.impact_open'))->toEqual(108000)
        ->and($res->json('meta.by_category.customers'))->toBe(1);
    expect($this->actingAs($this->owner)->getJson("{$url}?category=sales")->json('data'))->toHaveCount(0)
        ->and($this->actingAs($this->owner)->getJson("{$url}?severity=opportunity")->json('data'))->toHaveCount(1);
    $id = $res->json('data.0.id');
    $this->actingAs($this->owner)->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('status', 'seen');
    $settings = $this->actingAs($this->owner)->getJson("{$url}/settings")->assertOk();
    expect($settings->json('detectors'))->toHaveCount(23)->and(collect($settings->json('detectors'))->where('enabled', false)->pluck('number')->all())->toBe([21, 22, 23])
        ->and(collect($settings->json('assumptions'))->pluck('key'))->toContain('cart_recovery_rate');
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($url)->assertOk();
    $this->actingAs($viewer)->patchJson("{$url}/{$id}", ['status' => 'dismissed'])->assertForbidden();
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->patchJson("{$url}/{$id}", ['status' => 'actioned'])->assertOk()->assertJsonPath('status', 'actioned');
    expect(DB::table('audit_logs')->where('action', 'insight.actioned')->exists())->toBeTrue()
        ->and($this->actingAs($this->owner)->getJson($url)->json('meta.actioned_month'))->toBe(1);
});

it('TopInsights da los hechos para el analista: por impacto, sin datos personales', function () {
    insightsAtRisk($this, 10);
    insightsRun($this, ['valuable_at_risk']);
    app(CurrentWorkspace::class)->run($this->ws, fn () => Insight::query()->create(['detector_key' => 'search_no_results', 'fingerprint' => 'search_no_results:queries',
        'severity' => 'warning', 'title' => 'Buscan ana@ejemplo.com', 'facts' => ['queries' => [['query' => 'llamar 300 123 4567', 'searches' => 9]]],
        'estimated_impact_ws' => '500000', 'status' => 'new']));
    $facts = TopInsights::facts($this->ws->fresh());
    expect($facts)->toHaveCount(2)->and($facts[0]['detector'])->toBe('search_no_results')->and($facts[0]['impact'])->toBe(500000.0)
        ->and($facts[0]['title'])->toBe('Buscan [correo]')->and($facts[0]['facts']['queries'][0]['query'])->toBe('llamar [teléfono]')
        ->and($facts[1]['impact_formula'])->toBe('10 clientes × 18 % de reactivación de referencia × $100.000 de ticket = $180.000')
        ->and($facts[1]['category'])->toBe('customers');
});

it('aislamiento: los hallazgos y los supuestos de otro espacio de trabajo no se ven', function () {
    insightsAtRisk($this, 10);
    insightsRun($this, ['valuable_at_risk']);
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/insights/assumptions/reactivation_rate", ['value' => 0.3])->assertOk();
    $other = $this->userWith2fa();
    $ws2 = $this->workspaceFor($other);
    $res = $this->actingAs($other)->getJson("/api/w/{$ws2->publicId()}/insights")->assertOk();
    expect($res->json('data'))->toHaveCount(0)->and(TopInsights::facts($ws2))->toBe([]);
    $settings = $this->actingAs($other)->getJson("/api/w/{$ws2->publicId()}/insights/settings")->assertOk();
    expect(collect($settings->json('assumptions'))->firstWhere('key', 'reactivation_rate')['value'])->toEqual(0.18);
    $id = insightsOf($this)->first()->publicId();
    $this->actingAs($other)->getJson("/api/w/{$ws2->publicId()}/insights/{$id}")->assertNotFound();
    $this->actingAs($other)->postJson("/api/w/{$ws2->publicId()}/insights/{$id}/act")->assertNotFound();
});
