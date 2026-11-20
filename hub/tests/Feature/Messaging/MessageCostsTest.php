<?php

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Queries\CampaignReport;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Queries\FlowAnalytics;
use App\Domain\Messaging\Channels\Drivers\MetaCloudDriver;
use App\Domain\Messaging\Channels\Drivers\SesDriver;
use App\Domain\Messaging\Channels\Drivers\TwilioSmsDriver;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\WaRates;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * F11: el costo estimado de cada mensaje se guarda al enviarlo (USD, por país y categoría) y se muestra, marcado como
 * estimado, en la moneda del espacio de trabajo con la tasa del día, junto a las ventas atribuidas, el retorno y el
 * costo por venta: en el tablero de Mensajería (por canal y por flujo o campaña), el informe de la campaña y la
 * analítica del flujo. Lo archivado (R14) también suma.
 */

/** Un mensaje saliente para las cuentas de costo. */
function costMessage(string $workspaceId, array $attrs): string
{
    $id = (string) Str::ulid();
    DB::table('messages')->insert(array_replace([
        'id' => $id, 'workspace_id' => $workspaceId, 'contact_id' => (string) Str::ulid(), 'channel' => 'whatsapp', 'direction' => 'out',
        'kind' => 'marketing', 'source_type' => 'flow', 'status' => 'sent', 'sent_at' => now(), 'cost_estimated' => 0.0125, 'cost_currency' => 'USD',
        'created_at' => now()->subHour(), 'updated_at' => now(),
    ], $attrs));

    return $id;
}

it('el costo estimado se guarda al enviar por categoría y país, con los 1.000 mensajes de servicio gratis', function () {
    $ws = $this->workspaceFor($this->userWith2fa());
    $account = app(CurrentWorkspace::class)->run($ws, fn () => ChannelAccount::query()->create([
        'workspace_id' => $ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp', 'status' => 'active', 'external_id' => '1111',
    ]));
    $meta = app(MetaCloudDriver::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00', 'UTC'));
    // Tarifas de Meta desde el 1 oct 2026 (USD por mensaje entregado).
    expect($meta->estimateCost($account, 'CO', MessageKind::Marketing))->toBe(0.0125)
        ->and($meta->estimateCost($account, 'CO', MessageKind::Utility))->toBe(0.0008)
        ->and($meta->estimateCost($account, 'CO', MessageKind::Transactional))->toBe(0.0008)
        ->and($meta->estimateCost($account, 'CO', MessageKind::Authentication))->toBe(0.0008)
        ->and($meta->estimateCost($account, 'MX', MessageKind::Marketing))->toBe(0.0397)
        ->and($meta->estimateCost($account, 'VE', MessageKind::Marketing))->toBe(0.074)
        ->and($meta->estimateCost($account, 'VE', MessageKind::Utility))->toBe(0.0113);

    // Servicio: gratis hasta el mensaje 1.000 del número en el mes; desde el 1.001, la tarifa.
    expect($meta->estimateCost($account, 'CO', MessageKind::Service))->toBe(0.0);
    Cache::put('ws:'.$ws->id.':wa_service:'.$account->id.':202611', 1000, 3600);
    expect($meta->estimateCost($account, 'CO', MessageKind::Service))->toBe(0.0008)
        // Otro número tiene su propia cuenta; antes del 1 oct 2026 el servicio no se cobraba.
        ->and(WaRates::estimate($account, 'CO', MessageKind::Service, CarbonImmutable::parse('2026-09-15')))->toBe(0.0);

    // Correo (SES, USD 0,10 por 1.000) y SMS (Twilio por segmento, según el país).
    expect(app(SesDriver::class)->estimateCost($account, 'CO', MessageKind::Marketing))->toBe(0.0001)
        ->and(app(TwilioSmsDriver::class)->estimateCost($account, 'CO', MessageKind::Marketing))->toBe(0.0592)
        ->and(app(TwilioSmsDriver::class)->estimateCost($account, 'MX', MessageKind::Utility))->toBe(0.1819)
        ->and(app(TwilioSmsDriver::class)->estimateCost($account, 'AR', MessageKind::Utility))->toBe(0.0592);
});

it('muestra costo, ventas, retorno y costo por venta en la moneda del espacio de trabajo, por canal, flujo y campaña', function () {
    $this->useIngestInfrastructure();
    config(['igr.analytics.cache' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00', 'UTC'));
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    [$site, $secret] = $this->connectedSite($ws);
    $flow = (string) Str::ulid();
    DB::table('flows')->insert(['id' => $flow, 'workspace_id' => $ws->id, 'name' => 'Carrito abandonado', 'status' => 'active', 'trigger' => json_encode(['type' => 'event']), 'created_at' => now(), 'updated_at' => now()]);
    $campaign = (string) Str::ulid();
    DB::table('campaigns')->insert(['id' => $campaign, 'workspace_id' => $ws->id, 'name' => 'Black Friday', 'channel' => 'email', 'status' => 'sent', 'audience' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
    // Tasas del día: 4.000 COP el 15 y 4.100 desde el 16 (el 17 rige la última conocida).
    DB::table('fx_rates')->insert([
        ['date' => '2026-11-15', 'base' => 'USD', 'quote' => 'COP', 'rate' => 4000, 'source' => 'open.er-api.com', 'fetched_at' => now()],
        ['date' => '2026-11-16', 'base' => 'USD', 'quote' => 'COP', 'rate' => 4100, 'source' => 'open.er-api.com', 'fetched_at' => now()],
    ]);

    $id = $ws->id;
    // WhatsApp del flujo: Meta cobra los entregados.
    $wa1 = costMessage($id, ['flow_id' => $flow, 'status' => 'delivered', 'delivered_at' => now(), 'created_at' => '2026-11-15 15:00:00']);
    costMessage($id, ['flow_id' => $flow, 'status' => 'read', 'delivered_at' => now(), 'read_at' => now()]);
    costMessage($id, ['flow_id' => $flow, 'status' => 'sent']);                                     // enviado, no entregado: no se cobra
    // Correo de la campaña: SES cobra los enviados; el fallido no salió; SMTP no tiene costo estimado.
    costMessage($id, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $campaign, 'cost_estimated' => 0.0001]);
    costMessage($id, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $campaign, 'cost_estimated' => 0.0001, 'status' => 'failed', 'sent_at' => null]);
    costMessage($id, ['channel' => 'email', 'source_type' => 'campaign', 'campaign_id' => $campaign, 'cost_estimated' => null, 'provider' => 'smtp']);
    // Lo archivado del flujo (R14) suma sus conteos y su costo.
    DB::table('message_archive_daily')->insert(['workspace_id' => $id, 'date' => '2026-11-16', 'channel' => 'whatsapp', 'direction' => 'out', 'kind' => 'marketing',
        'source_type' => 'flow', 'flow_id' => $flow, 'campaign_id' => '', 'total' => 10, 'sent' => 10, 'delivered' => 10, 'cost_usd' => 0.125, 'created_at' => now(), 'updated_at' => now()]);

    // Una venta atribuida al WhatsApp del flujo.
    $this->postSigned($site, $secret, [$this->envelope($site, 'order.paid', ['order' => $this->orderSnapshot(['created_at' => now()->toIso8601String(), 'paid_at' => now()->toIso8601String()])])]);
    $this->runWorker();
    DB::table('orders')->where('workspace_id', $id)->update(['attributed_message_id' => $wa1, 'attributed_flow_id' => $flow]);
    $revenue = (float) DB::table('orders')->where('workspace_id', $id)->value('total_ws');

    // WhatsApp: 0,0125 × 4.000 + 0,0125 × 4.100 + 0,125 × 4.100 (archivo) = 614,75 COP. Correo: 0,0001 × 4.100 = 0,41.
    $waCost = 0.0125 * 4000 + 0.0125 * 4100 + 0.125 * 4100;
    $data = $this->actingAs($owner)->getJson("/api/w/{$ws->publicId()}/analytics/messaging?compare=none&from=2026-11-01&to=2026-11-17")->assertOk()->json('data');
    expect($data['cost'])->toMatchArray(['currency' => 'COP', 'estimated' => true, 'attribution_url' => 'https://www.exchangerate-api.com'])
        ->and($data['cost']['fx']['rate'])->toEqual(4100)
        ->and($data['totals']['sent'])->toBe(15)
        ->and($data['totals']['cost'])->toEqualWithDelta($waCost + 0.41, 0.01)
        ->and($data['totals']['cost_usd'])->toEqualWithDelta(0.0125 * 2 + 0.125 + 0.0001, 0.00001);
    $channels = collect($data['by_channel'])->keyBy('channel');
    expect($channels['whatsapp']['cost'])->toEqualWithDelta($waCost, 0.01)
        ->and($channels['whatsapp']['roi'])->toEqualWithDelta($revenue / $waCost, 0.01)
        ->and($channels['whatsapp']['cost_per_sale'])->toEqualWithDelta($waCost, 0.01)
        ->and($channels['email']['cost'])->toEqualWithDelta(0.41, 0.001)
        ->and($channels['email']['roi'])->toEqual(0)
        ->and($channels['email']['cost_per_sale'])->toBeNull();
    $sources = collect($data['by_source'])->keyBy('id');
    expect($sources['fl_'.$flow])->toMatchArray(['name' => 'Carrito abandonado', 'orders' => 1, 'sent' => 13])
        ->and($sources['fl_'.$flow]['cost'])->toEqualWithDelta($waCost, 0.01)
        ->and($sources['cmp_'.$campaign]['cost'])->toEqualWithDelta(0.41, 0.001);

    // Informe de la campaña y analítica del flujo con lo mismo.
    $report = app(CurrentWorkspace::class)->run($ws, fn () => app(CampaignReport::class)->handle($ws, Campaign::query()->findOrFail($campaign)));
    expect($report['cost']['cost'])->toEqualWithDelta(0.41, 0.001)->and($report['cost']['meta']['currency'])->toBe('COP')
        ->and($report['delivery']['sent'])->toBe(2);
    $analytics = app(CurrentWorkspace::class)->run($ws, fn () => app(FlowAnalytics::class)->handle(Flow::query()->findOrFail($flow), CarbonImmutable::parse('2026-11-01')));
    expect($analytics['summary']['cost'])->toEqualWithDelta($waCost, 0.01)
        ->and($analytics['summary']['roi'])->toEqualWithDelta($revenue / $waCost, 0.01)
        ->and($analytics['summary']['cost_per_sale'])->toEqualWithDelta($waCost, 0.01)
        ->and($analytics['cost_meta']['estimated'])->toBeTrue();

    // Sin tasa para la moneda: el costo local queda vacío (la SPA lo muestra en USD) y no se inventa un retorno.
    DB::table('fx_rates')->delete();
    $data = $this->actingAs($owner)->getJson("/api/w/{$ws->publicId()}/analytics/messaging?compare=none&from=2026-11-01&to=2026-11-17")->assertOk()->json('data');
    expect($data['totals']['cost'])->toBeNull()->and($data['totals']['roi'])->toBeNull()->and($data['totals']['cost_usd'])->toBeGreaterThan(0.15)
        ->and($data['cost']['fx']['rate'])->toBeNull();
});

it('el costo y el archivo de un espacio de trabajo no se ven desde otro', function () {
    config(['igr.analytics.cache' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:00', 'UTC'));
    $ownerA = $this->userWith2fa();
    $a = $this->workspaceFor($ownerA, 'Tienda A');
    $ownerB = $this->userWith2fa();
    $b = $this->workspaceFor($ownerB, 'Tienda B', 'MXN');
    $flow = (string) Str::ulid();
    DB::table('flows')->insert(['id' => $flow, 'workspace_id' => $a->id, 'name' => 'Flujo de A', 'status' => 'active', 'trigger' => json_encode(['type' => 'event']), 'created_at' => now(), 'updated_at' => now()]);
    costMessage($a->id, ['flow_id' => $flow, 'status' => 'delivered', 'delivered_at' => now()]);
    DB::table('message_archive_daily')->insert(['workspace_id' => $a->id, 'date' => '2026-11-16', 'channel' => 'whatsapp', 'direction' => 'out', 'kind' => 'marketing',
        'source_type' => 'flow', 'flow_id' => $flow, 'campaign_id' => '', 'total' => 5, 'sent' => 5, 'delivered' => 5, 'cost_usd' => 0.0625, 'created_at' => now(), 'updated_at' => now()]);

    $mine = $this->actingAs($ownerA)->getJson("/api/w/{$a->publicId()}/analytics/messaging?compare=none&from=2026-11-01&to=2026-11-17")->assertOk()->json('data');
    $theirs = $this->actingAs($ownerB)->getJson("/api/w/{$b->publicId()}/analytics/messaging?compare=none&from=2026-11-01&to=2026-11-17")->assertOk()->json('data');
    expect($mine['totals']['sent'])->toBe(6)->and($mine['totals']['cost_usd'])->toEqualWithDelta(0.075, 0.00001)
        ->and($theirs['empty'])->toBeTrue()->and($theirs['totals']['cost_usd'])->toEqual(0)->and($theirs['totals']['sent'])->toBe(0)
        ->and($theirs['cost']['currency'])->toBe('MXN');
    // Ni con el ID del flujo de A en la ruta de B.
    $this->actingAs($ownerB)->getJson("/api/w/{$b->publicId()}/flows/fl_{$flow}/analytics")->assertNotFound();
    $this->actingAs($ownerB)->getJson("/api/w/{$a->publicId()}/analytics/messaging")->assertNotFound();
});
