<?php

use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * El Inicio (docs/diseno/rediseno-v2.md §5): dinero generado del mes y del anterior por estrategia, el potencial
 * cuando no hay dinero, «Para hoy», las estrategias activas y sugeridas, los KPI con su serie y los avisos del sitio.
 */

beforeEach(function () {
    FakeAiGateway::install();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'America/Bogota'));
});

function home($test): array
{
    return $test->actingAs($test->owner)->getJson("/api/w/{$test->ws->publicId()}/home")->assertOk()->json();
}

function paidOrder($test, string $amount, ?string $flowId = null, ?CarbonImmutable $at = null): void
{
    $at ??= now();
    DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => (string) Str::uuid(),
        'status' => 'processing', 'is_paid' => true, 'paid_at' => $at, 'currency' => 'COP', 'total' => $amount, 'total_ws' => $amount,
        'attributed_flow_id' => $flowId, 'created_at' => $at, 'updated_at' => $at]);
}

it('un negocio nuevo: la tarjeta de progreso, conectar la web primero y nada de dinero todavía', function () {
    $this->strategyWorld(site: false, channels: []);
    $res = home($this);
    expect($res['setup'])->toMatchArray(['status' => 'not_started', 'visible' => true, 'current_step' => 'business', 'completed' => 0, 'total' => 5])
        ->and($res['money']['total'])->toBe('0.00')->and($res['money']['low'])->toBeTrue()
        ->and($res['today'][0])->toMatchArray(['kind' => 'connect_site', 'to' => 'sitios?conectar=1', 'title' => 'Conecta tu web'])
        ->and($res['strategies']['active'])->toBe([])
        ->and(count($res['strategies']['suggested']))->toBeGreaterThanOrEqual(2)
        ->and($res['health'])->toBe([])
        ->and($res['greeting'])->toHaveKey('name');
});

it('el dinero generado del mes y del anterior con el desglose por estrategia', function () {
    $this->strategyWorld();
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    $flow = $this->inWs(fn () => Flow::query()->where('template_key', 'abandoned_cart')->value('id'));
    paidOrder($this, '300000.00', $flow);
    paidOrder($this, '100000.00', $flow, CarbonImmutable::parse('2026-10-15 12:00', 'America/Bogota'));
    paidOrder($this, '700000.00');  // venta del negocio que no generó la plataforma
    DB::table('sites')->where('id', $this->site->id)->update(['last_event_at' => now()->addMinutes(2)]);

    $res = home($this);
    expect($res['money'])->toMatchArray(['total' => '300000.00', 'orders' => 1, 'store_total' => '1000000.00', 'share_pct' => 30.0, 'change_pct' => 200.0, 'low' => false])
        ->and($res['money']['previous']['total'])->toBe('100000.00')
        ->and($res['money']['breakdown'])->toBe([['strategy' => 'abandoned_cart', 'bucket' => 'other_flows', 'revenue' => '300000.00', 'orders' => 1, 'name' => 'Recupera carritos abandonados']])
        ->and($res['potential'])->toBeNull()
        // Las activas, de la que más generó a la que menos (los avisos de stock vienen prendidos en una tienda).
        ->and(collect($res['strategies']['active'])->pluck('key')->all())->toBe(['abandoned_cart', 'back_in_stock'])
        ->and($res['strategies']['active'][0]['result']['revenue'])->toBe('300000.00')
        ->and($res['kpis']['kind'])->toBe('store')
        ->and(array_keys($res['kpis']['metrics']))->toBe(['revenue', 'orders', 'new_customers', 'returning_customers'])
        ->and($res['kpis']['series'])->toHaveCount(30)
        ->and($res['kpis']['series'][29])->toHaveKeys(['date', 'revenue', 'orders']);
});

it('sin dinero generado muestra el potencial con datos reales y lo sugiere para hoy', function () {
    $this->strategyWorld();
    foreach (range(1, 6) as $i) {
        DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => 'c'.$i,
            'status' => 'abandoned', 'subtotal' => 700000, 'subtotal_ws' => 700000, 'abandoned_at' => now()->subDays(2), 'last_activity_at' => now()->subDays(2),
            'created_at' => now(), 'updated_at' => now()]);
    }
    $res = home($this);
    expect($res['potential'])->toMatchArray(['strategy' => 'abandoned_cart', 'count' => 6, 'value' => '4200000.00', 'status' => 'available'])
        ->and($res['potential']['summary'])->toContain('6 carritos abandonados por $4.200.000');
    $suggest = collect($res['today'])->firstWhere('kind', 'activate_strategy');
    expect($suggest)->toMatchArray(['strategy' => 'abandoned_cart', 'to' => 'estrategias/abandoned_cart'])
        ->and($suggest['why'])->toBe($res['potential']['summary']);
});

it('«Para hoy» prioriza estrategias con problemas y conversaciones esperando, máximo 5', function () {
    $this->strategyWorld(channels: ['email', 'whatsapp']);
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->where('channel', 'email')->update(['status' => 'disconnected']);
    $account = (string) DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->value('id');
    foreach (range(1, 2) as $i) {
        DB::table('conversations')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => strtoupper((string) Str::ulid()),
            'channel_account_id' => $account, 'status' => 'open', 'awaiting_since' => now()->subMinutes(30 * $i), 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['critical', 'warning', 'opportunity', 'info'] as $i => $severity) {
        DB::table('insights')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'detector_key' => 'd'.$i, 'severity' => $severity,
            'title' => 'Hallazgo '.$severity, 'facts' => json_encode([]), 'estimated_impact_ws' => 100000 * ($i + 1), 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
    }

    $today = home($this)['today'];
    expect($today)->toHaveCount(5)
        // El correo caído afecta al carrito abandonado y a los avisos de stock, que usan ese canal.
        ->and(array_column($today, 'kind'))->toBe(['strategy_attention', 'strategy_attention', 'insight', 'reply_conversations', 'insight'])
        ->and(array_column($today, 'strategy'))->toBe(['abandoned_cart', 'back_in_stock', null, null, null])
        ->and($today[0])->toMatchArray(['title' => '«Recupera carritos abandonados» necesita tu atención', 'to' => 'estrategias/abandoned_cart'])
        ->and($today[0]['why'])->toContain('desconectado')
        ->and($today[2])->toMatchArray(['title' => 'Hallazgo critical', 'severity' => 'critical'])
        ->and($today[2]['why'])->toBe('Impacto estimado: $100.000.')
        ->and($today[3])->toMatchArray(['title' => '2 clientes esperan tu respuesta', 'to' => 'bandeja', 'count' => 2]);
});

it('un negocio de servicios ve sus contactos y clientes ganados como KPI', function () {
    $this->strategyWorld();
    DB::table('sites')->where('id', $this->site->id)->update(['platform' => 'wordpress']);
    DB::table('leads')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => strtoupper((string) Str::ulid()),
        'origin' => 'form', 'stage' => 'won', 'won_at' => now(), 'value_ws' => '1500000.00', 'created_at' => now()->subDay(), 'updated_at' => now()]);

    $res = home($this);
    expect($res['business_type'])->toBe('services')
        ->and($res['kpis']['kind'])->toBe('services')
        ->and($res['kpis']['metrics']['won_value']['value'])->toEqual(1500000)
        ->and($res['kpis']['metrics']['leads']['value'])->toEqual(1)
        ->and($res['money']['breakdown'][0])->toMatchArray(['strategy' => 'lead_response', 'revenue' => '1500000.00'])
        ->and(collect($res['strategies']['suggested'])->pluck('key')->first())->toBe('lead_response');
});

it('la caché se invalida al activar o pausar y no se mezcla entre espacios de trabajo', function () {
    $this->strategyWorld();
    $active = fn () => collect(home($this)['strategies']['active'])->pluck('key')->all();
    expect($active())->not->toContain('abandoned_cart');
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    expect($active())->toContain('abandoned_cart');
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/pause'))->assertOk();
    expect($active())->not->toContain('abandoned_cart');

    $mine = [$this->ws, $this->owner];
    $this->strategyWorld();
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    [$this->ws, $this->owner] = $mine;
    expect($active())->not->toContain('abandoned_cart');
});

it('permisos: el Inicio pide ver los tableros', function () {
    $this->strategyWorld();
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($agent)->getJson("/api/w/{$this->ws->publicId()}/home")->assertForbidden();
    $this->actingAs($viewer)->getJson("/api/w/{$this->ws->publicId()}/home")->assertOk();
});
