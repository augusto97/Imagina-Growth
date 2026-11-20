<?php

use App\Analytics\Dashboards\Attribution;
use App\Analytics\Http\AnalyticsController;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->useIngestInfrastructure();
    config(['igr.analytics.cache' => false]);
    $this->artisan('igr:seed-demo', ['--customers' => 50, '--orders' => 240, '--process' => true, '--browsing-months' => 6])->assertSuccessful();
    $this->ws = Workspace::query()->where('name', 'Pinturas Demo')->firstOrFail();
    $this->owner = $this->userWith2fa();
    $this->ws->users()->attach($this->owner->id, ['role' => 'owner']);
    $this->url = "/api/w/{$this->ws->publicId()}/analytics";
    $this->range = '?from='.now($this->ws->timezone)->subDays(179)->toDateString().'&to='.now($this->ws->timezone)->toDateString();
});

it('responde los 12 tableros con datos', function () {
    foreach (array_keys(AnalyticsController::DASHBOARDS) as $dashboard) {
        $res = $this->actingAs($this->owner)->getJson("{$this->url}/{$dashboard}{$this->range}")->assertOk();
        expect($res->json('dashboard'))->toBe($dashboard)->and($res->json('data'))->toBeArray();
    }
    $this->actingAs($this->owner)->getJson("{$this->url}/inventado")->assertNotFound();
});

it('las ventas del tablero son las de los pedidos válidos (misma definición que WooCommerce)', function () {
    $res = $this->actingAs($this->owner)->getJson("{$this->url}/overview{$this->range}&compare=none")->assertOk();
    $period = $res->json('period');
    $tz = $this->ws->timezone;
    $expected = (float) DB::table('orders')->where('workspace_id', $this->ws->id)->where('is_paid', true)
        ->where('created_at', '>=', CarbonImmutable::parse($period['from'], $tz)->utc())
        ->where('created_at', '<', CarbonImmutable::parse($period['to'], $tz)->addDay()->utc())
        ->sum('total_ws');
    $orders = (int) DB::table('orders')->where('workspace_id', $this->ws->id)->where('is_paid', true)
        ->where('created_at', '>=', CarbonImmutable::parse($period['from'], $tz)->utc())
        ->where('created_at', '<', CarbonImmutable::parse($period['to'], $tz)->addDay()->utc())->count();

    expect((float) $res->json('data.kpis.revenue'))->toBe(round($expected, 2))
        ->and($res->json('data.kpis.orders'))->toBe($orders)
        ->and($res->json('data.kpis.sessions'))->toBeGreaterThan(1000)
        ->and($res->json('data.attributed.available'))->toBeTrue()
        ->and(array_sum(array_column($res->json('data.series'), 'revenue')))->toEqualWithDelta($expected, 0.01);
});

it('el embudo baja paso a paso y el canal tiene atribución', function () {
    $funnel = $this->actingAs($this->owner)->getJson("{$this->url}/funnel{$this->range}")->assertOk()->json('data.steps');
    $values = array_column($funnel, 'value');
    expect($values[0])->toBeGreaterThan($values[4])->and($values[4])->toBeGreaterThan(0);
    for ($i = 1; $i < count($values); $i++) {
        expect($values[$i])->toBeLessThanOrEqual($values[$i - 1]);
    }

    $channels = $this->actingAs($this->owner)->getJson("{$this->url}/channels{$this->range}")->assertOk()->json('data');
    expect(collect($channels['channels'])->pluck('channel'))->toContain('organic_search', 'paid_meta', 'ai_assistants')
        ->and(collect($channels['models'])->sum('last_click'))->toEqualWithDelta(collect($channels['models'])->sum('linear'), 1.0);
});

it('aplica filtros y la consulta estructurada suma lo mismo por fecha que en total', function () {
    $all = $this->actingAs($this->owner)->getJson("{$this->url}/query{$this->range}&metrics=sessions,revenue&dimensions=date")->assertOk();
    expect(array_sum(array_column($all->json('rows'), 'sessions')))->toBe($all->json('totals.sessions'));
    $mobile = $this->actingAs($this->owner)->getJson("{$this->url}/query{$this->range}&metrics=sessions&device=mobile")->assertOk();
    expect($mobile->json('totals.sessions'))->toBeLessThan($all->json('totals.sessions'))->toBeGreaterThan(0);
    $this->actingAs($this->owner)->getJson("{$this->url}/query?metrics=inventada")->assertUnprocessable();
});

it('los permisos y el aislamiento entre espacios de trabajo se respetan', function () {
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($viewer)->getJson("{$this->url}/overview")->assertOk();
    $this->actingAs($agent)->getJson("{$this->url}/overview")->assertForbidden();

    $other = $this->workspaceFor($owner2 = $this->userWith2fa(), 'Otra tienda');
    $res = $this->actingAs($owner2)->getJson("/api/w/{$other->publicId()}/analytics/overview{$this->range}")->assertOk();
    expect($res->json('data.kpis.revenue'))->toEqual(0)->and($res->json('data.kpis.sessions'))->toBe(0);
    $this->actingAs($owner2)->getJson("{$this->url}/overview")->assertNotFound();
});

it('reparte el crédito según el modelo de atribución', function () {
    $a = new Attribution;
    $ref = new ReflectionProperty($a, 'touches');
    $ref->setValue($a, ['c1' => [[1000, 'paid_meta'], [2000, 'organic_search'], [3000, 'email'], [4000, 'direct']]]);
    expect($a->credit('c1', 5000, null, 'last_click'))->toBe(['email' => 1.0])
        ->and($a->credit('c1', 5000, null, 'first_click'))->toBe(['paid_meta' => 1.0])
        ->and(array_sum($a->credit('c1', 5000, null, 'linear')))->toEqualWithDelta(1.0, 1e-9)
        ->and($a->credit('c1', 5000, null, 'position'))->toEqualWithDelta(['paid_meta' => 0.4, 'direct' => 0.4, 'organic_search' => 0.1, 'email' => 0.1], 1e-9)
        ->and($a->credit('nadie', 5000, 'paid_google', 'last_click'))->toBe(['paid_google' => 1.0]);
});

it('el Inicio deja de mostrar un hallazgo descartado aunque el tablero esté en caché', function () {
    config(['igr.analytics.cache' => true]);
    $insight = app(CurrentWorkspace::class)->run($this->ws, fn () => Insight::query()->forceCreate([
        'detector_key' => 'valuable_at_risk', 'fingerprint' => 'valuable_at_risk:prueba', 'severity' => 'critical',
        'title' => 'Hallazgo de prueba', 'facts' => ['customers' => 9], 'estimated_impact_ws' => '999999999', 'status' => 'new',
    ]));
    $ids = fn () => collect($this->actingAs($this->owner)->getJson("{$this->url}/overview{$this->range}")->assertOk()->json('data.insights'))->pluck('id');

    expect($ids())->toContain($insight->publicId());
    $this->actingAs($this->owner)->patchJson("/api/w/{$this->ws->publicId()}/insights/{$insight->publicId()}", ['status' => 'dismissed'])->assertOk();
    expect($ids())->not->toContain($insight->publicId());
});

it('el inicio muestra el dinero generado del periodo con la regla del reporte mensual', function () {
    $order = DB::table('orders')->where('workspace_id', $this->ws->id)->where('is_paid', true)
        ->where('paid_at', '>=', now()->subDays(20))->orderByDesc('paid_at')->first();
    $flow = (string) Str::ulid();
    DB::table('flows')->insert([
        'id' => $flow, 'workspace_id' => $this->ws->id, 'name' => 'Carrito abandonado', 'status' => 'active',
        'trigger' => json_encode(['type' => 'event']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('orders')->where('id', $order->id)->update(['attributed_flow_id' => $flow]);

    $res = $this->actingAs($this->owner)->getJson("{$this->url}/overview{$this->range}&compare=none")->assertOk();

    expect($res->json('data.attributed.revenue'))->toEqualWithDelta((float) $order->total_ws, 0.01)
        ->and($res->json('data.attributed.orders'))->toBe(1)
        ->and($res->json('data.attributed.modules.0.key'))->toBe('flows')
        ->and($res->json('data.attributed.share_pct'))->toBeGreaterThan(0);
});

it('la caché de los tableros aguanta el tráfico en vivo y se renueva al minuto (R9)', function () {
    config(['igr.analytics.cache' => true]);
    $site = Site::withoutWorkspaceScope()->where('workspace_id', $this->ws->id)->firstOrFail();
    $calls = 0;
    $compute = function () use (&$calls) {
        return ++$calls;
    };
    $runner = MetricQueryRunner::class;

    $site->forceFill(['last_event_at' => now()->startOfMinute()->addSeconds(5)])->save();
    $runner::remember($this->ws, 'prueba', $compute);
    $site->forceFill(['last_event_at' => now()->startOfMinute()->addSeconds(40)])->save(); // otro evento, mismo minuto
    $runner::remember($this->ws, 'prueba', $compute);
    expect($calls)->toBe(1);

    $site->forceFill(['last_event_at' => now()->startOfMinute()->addMinutes(2)])->save();
    $runner::remember($this->ws, 'prueba', $compute);
    expect($calls)->toBe(2);
});
