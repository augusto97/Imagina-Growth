<?php

use App\Domain\Agency\Queries\MoneyGenerated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Agency\AgencyWorld;

function agencyUlid(): string
{
    return strtoupper((string) Str::ulid());
}

/** Un pedido por módulo (y los que no cuentan) en noviembre de 2026; uno de flujo en octubre. */
function agencySeedMoney(AgencyWorld $w): void
{
    $ws = $w->a;
    $msg = function (string $source) use ($ws): string {
        $id = agencyUlid();
        DB::table('messages')->insert(['id' => $id, 'workspace_id' => $ws->id, 'channel' => 'whatsapp', 'kind' => 'marketing', 'source_type' => $source, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $cart = function (array $cols) use ($ws): string {
        $id = agencyUlid();
        DB::table('carts')->insert($cols + ['id' => $id, 'workspace_id' => $ws->id, 'site_id' => 'S1', 'token' => Str::random(20), 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };

    AgencyWorld::order($ws, 100000, ['attributed_affiliate_id' => agencyUlid(), 'attributed_flow_id' => agencyUlid()]);
    AgencyWorld::order($ws, 200000, ['attributed_flow_id' => agencyUlid()]);
    AgencyWorld::order($ws, 50000, ['attributed_campaign_id' => agencyUlid()]);
    $agentOrder = AgencyWorld::order($ws, 70000);
    $agentCart = $cart(['order_id' => $agentOrder]);
    DB::table('ai_agent_turns')->insert(['id' => agencyUlid(), 'workspace_id' => $ws->id, 'conversation_id' => agencyUlid(), 'mode' => 'auto', 'status' => 'replied', 'cart_id' => $agentCart, 'created_at' => now(), 'updated_at' => now()]);
    AgencyWorld::order($ws, 30000, ['attributed_message_id' => $msg('inbox')]);
    AgencyWorld::order($ws, 40000, ['attributed_widget_id' => agencyUlid()]);
    $cart(['recovered_order_id' => AgencyWorld::order($ws, 60000)]);
    AgencyWorld::order($ws, 20000, ['attributed_message_id' => $msg('api')]);
    AgencyWorld::order($ws, 500000);                                                       // la tienda sola
    AgencyWorld::order($ws, 999, ['is_paid' => false, 'paid_at' => null, 'attributed_flow_id' => agencyUlid()]);
    AgencyWorld::order($ws, 888, ['status' => 'cancelled', 'attributed_flow_id' => agencyUlid()]);
    AgencyWorld::order($ws, 100000, ['attributed_flow_id' => agencyUlid(), 'paid_at' => now()->subMonth()]); // octubre
    AgencyWorld::order($w->b, 777777, ['attributed_flow_id' => agencyUlid()]);             // otro cliente
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 15:00:00', 'America/Bogota'));
    $this->w = AgencyWorld::make($this);
    agencySeedMoney($this->w);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('cuenta cada pedido una sola vez, en el primer módulo que lo explica', function () {
    [$from, $to] = MoneyGenerated::month($this->w->a);
    $m = app(MoneyGenerated::class)->forWorkspace($this->w->a, $from, $to);
    $by = collect($m['modules'])->keyBy('key');

    expect($from->toDateString())->toBe('2026-11-01')
        ->and((float) $m['total'])->toBe(570000.0)
        ->and($m['orders'])->toBe(8)
        ->and((float) $m['store_total'])->toBe(1070000.0)
        ->and($m['store_orders'])->toBe(9)
        ->and($m['share_pct'])->toBe(53.3)
        ->and((float) $by['affiliates']['revenue'])->toBe(100000.0)
        ->and((float) $by['flows']['revenue'])->toBe(200000.0)
        ->and($by['flows']['orders'])->toBe(1)
        ->and((float) $by['campaigns']['revenue'])->toBe(50000.0)
        ->and((float) $by['ai_agent']['revenue'])->toBe(70000.0)
        ->and((float) $by['inbox']['revenue'])->toBe(30000.0)
        ->and((float) $by['widgets']['revenue'])->toBe(40000.0)
        ->and((float) $by['recovered_carts']['revenue'])->toBe(60000.0)
        ->and((float) $by['messages']['revenue'])->toBe(20000.0)
        ->and($m['modules'][0]['key'])->toBe('flows');

    // La suma por módulo es el total: nada se cuenta dos veces.
    expect(array_sum(array_map(fn ($x) => (float) $x['revenue'], $m['modules'])))->toBe(570000.0);

    [$prevFrom, $prevTo] = MoneyGenerated::month($this->w->a, $from->subDay());
    expect((float) app(MoneyGenerated::class)->forWorkspace($this->w->a, $prevFrom, $prevTo)['total'])->toBe(100000.0);
});

it('reparte el mismo dinero generado por el canal por el que llegó cada venta', function () {
    [$from, $to] = MoneyGenerated::month($this->w->a);
    $money = app(MoneyGenerated::class);
    $by = collect($money->byChannel($this->w->a, $from, $to))->keyBy('channel');

    // Afiliados → referidos; flujo y campaña sin mensaje → sin canal; agente → chats; bandeja y otros mensajes por WhatsApp;
    // widget y carrito restaurado → tu web.
    expect((float) $by['referrals']['revenue'])->toBe(100000.0)
        ->and((float) $by['other']['revenue'])->toBe(250000.0)
        ->and((float) $by['chats']['revenue'])->toBe(70000.0)
        ->and((float) $by['whatsapp']['revenue'])->toBe(50000.0)
        ->and($by['whatsapp']['orders'])->toBe(2)
        ->and((float) $by['web']['revenue'])->toBe(100000.0)
        ->and(array_sum(array_map(fn ($x) => (float) $x['revenue'], $by->all())))->toBe((float) $money->forWorkspace($this->w->a, $from, $to)['total'])
        ->and($by->keys()->first())->toBe('other');
});

it('en un negocio de servicios los leads ganados son las ventas y suman al módulo que los captó', function () {
    $ws = $this->w->b;
    [$from, $to] = MoneyGenerated::month($ws);
    $base = app(MoneyGenerated::class)->forWorkspace($ws, $from, $to); // los pedidos que ya trae el fixture
    $lead = function (string $origin, ?string $value, string $stage = 'won', ?string $wonAt = '2026-11-10 15:00:00') use ($ws): void {
        DB::table('leads')->insert(['id' => agencyUlid(), 'workspace_id' => $ws->id, 'contact_id' => agencyUlid(), 'stage' => $stage, 'origin' => $origin,
            'value_ws' => $value, 'won_at' => $stage === 'won' ? $wonAt : null, 'created_at' => now(), 'updated_at' => now()]);
    };
    $lead('form', '500000');
    $lead('whatsapp', '250000');
    $lead('booking', '300000');
    $lead('quote', '200000');
    $lead('manual', '1000000');                       // venta del negocio, no la captó la plataforma
    $lead('form', '900000', 'lost');                  // perdido: no cuenta
    $lead('form', '700000', 'proposal');              // abierto: no cuenta
    $lead('quote', '400000', 'won', '2026-10-20 15:00:00'); // otro mes

    $m = app(MoneyGenerated::class)->forWorkspace($ws, $from, $to);
    $by = collect($m['modules'])->keyBy('key');
    expect((float) $m['total'] - (float) $base['total'])->toBe(1250000.0)
        ->and($m['orders'] - $base['orders'])->toBe(4)
        ->and((float) $m['store_total'] - (float) $base['store_total'])->toBe(2250000.0)
        ->and($m['store_orders'] - $base['store_orders'])->toBe(5)
        ->and((float) $by['leads']['revenue'])->toBe(750000.0)
        ->and($by['leads']['orders'])->toBe(2)
        ->and((float) $by['bookings']['revenue'])->toBe(300000.0)
        ->and((float) $by['quotes']['revenue'])->toBe(200000.0);
});

it('el panel muestra dinero, cambio, salud, hallazgos críticos y facturación por cliente', function () {
    $w = $this->w;
    DB::table('insights')->insert([
        ['id' => agencyUlid(), 'workspace_id' => $w->a->id, 'detector_key' => 'x', 'severity' => 'critical', 'title' => 'Caída de ventas', 'facts' => '{}', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()],
        ['id' => agencyUlid(), 'workspace_id' => $w->a->id, 'detector_key' => 'y', 'severity' => 'critical', 'title' => 'Descartado', 'facts' => '{}', 'status' => 'dismissed', 'created_at' => now(), 'updated_at' => now()],
        ['id' => agencyUlid(), 'workspace_id' => $w->c->id, 'detector_key' => 'z', 'severity' => 'critical', 'title' => 'Ajeno', 'facts' => '{}', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $res = $this->actingAs($w->owner)->getJson($w->api($w->a, 'agency/overview'))->assertOk();
    $a = collect($res->json('clients'))->firstWhere('id', $w->a->publicId());
    expect((float) $a['money']['total'])->toBe(570000.0)
        ->and((float) $a['money']['previous_total'])->toBe(100000.0)
        ->and($a['money']['delta_pct'])->toEqual(470.0)
        ->and($a['money']['top_module'])->toBe('flows')
        ->and($a['critical_insights'])->toBe(1)
        ->and($a['health']['status'])->toBe('no_sites')
        ->and($res->json('totals.critical_insights'))->toBe(1)
        ->and((float) $res->json('totals.money_by_currency.COP'))->toBe(570000.0 + 777777.0);
});
