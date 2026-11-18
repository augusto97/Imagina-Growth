<?php

use App\Analytics\Ch;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdSpendDaily;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MeasurementFixtures;

uses(MeasurementFixtures::class);

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->base = "/api/w/{$this->ws->publicId()}/measurement";
});

it('crea, lista, actualiza y borra destinos sin devolver nunca los secretos', function () {
    $created = $this->actingAs($this->owner)->postJson("{$this->base}/destinations", [
        'platform' => 'meta', 'mode' => 'full', 'test_event_code' => 'TEST1',
        'credentials' => ['dataset_id' => '123456789012345', 'access_token' => 'EAAB-muy-secreto-9876'],
    ])->assertCreated()->json();

    expect($created['id'])->toStartWith('dst_')
        ->and($created['credentials']['access_token'])->toBe('••••9876')
        ->and($created['credentials']['dataset_id'])->toBe('123456789012345')
        ->and($created['pixel_id'])->toBe('123456789012345')
        ->and($created['events_enabled'])->toContain('order.paid')->not->toContain('page.viewed')
        ->and($created['endpoints'])->toBe(['meta_capi' => true]);
    expect(json_encode($this->actingAs($this->owner)->getJson("{$this->base}/destinations")->json()))->not->toContain('muy-secreto');
    expect(DB::table('ad_destinations')->value('config'))->not->toContain('muy-secreto'); // cifrado en reposo

    // Un campo vacío conserva el secreto guardado.
    $this->actingAs($this->owner)->patchJson("{$this->base}/destinations/{$created['id']}", [
        'events_enabled' => ['order.paid', 'page.viewed'], 'credentials' => ['access_token' => ''], 'mode' => 'with_pixel',
    ])->assertOk()->assertJsonPath('credentials.access_token', '••••9876')->assertJsonPath('mode', 'with_pixel');

    $this->actingAs($this->owner)->deleteJson("{$this->base}/destinations/{$created['id']}")->assertOk();
    expect(DB::table('audit_logs')->whereIn('action', ['measurement.destination_created', 'measurement.destination_updated', 'measurement.destination_deleted'])->count())->toBe(3)
        ->and(DB::table('audit_logs')->pluck('changes')->implode(' '))->not->toContain('muy-secreto');
});

it('exige las credenciales mínimas de cada plataforma', function () {
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations", ['platform' => 'tiktok', 'credentials' => ['pixel_code' => 'C9']])
        ->assertUnprocessable()->assertJsonValidationErrors('credentials.access_token');
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations", ['platform' => 'google', 'credentials' => ['measurement_id' => 'G-1']])
        ->assertUnprocessable();
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations", ['platform' => 'pinterest'])->assertUnprocessable();
});

it('marketing ve la medición pero no cambia destinos; agent no la ve', function () {
    $this->destination($this->ws, Platform::Meta);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);

    $this->actingAs($marketing)->getJson("{$this->base}/destinations")->assertOk()->assertJsonCount(1, 'destinations');
    $this->actingAs($marketing)->postJson("{$this->base}/destinations", ['platform' => 'meta'])->assertForbidden();
    $this->actingAs($agent)->getJson("{$this->base}/destinations")->assertForbidden();
});

it('prueba la conexión sin registrar conversiones reales', function () {
    $meta = $this->destination($this->ws, Platform::Meta);
    $google = $this->destination($this->ws, Platform::Google);
    $tiktok = $this->destination($this->ws, Platform::TikTok);
    Http::fake([
        'graph.facebook.com/*' => Http::response(['id' => '123456789012345', 'name' => 'Píxel']),
        'www.google-analytics.com/debug/mp/collect*' => Http::response(['validationMessages' => []]),
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
        'datamanager.googleapis.com/*' => Http::response(['requestId' => 'r1']),
    ]);

    $this->actingAs($this->owner)->postJson("{$this->base}/destinations/{$meta->publicId()}/test")->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'graph.facebook.com') && str_contains($r->url(), 'fields=id%2Cname'));

    $this->actingAs($this->owner)->postJson("{$this->base}/destinations/{$google->publicId()}/test")->assertOk()
        ->assertJsonPath('ok', true)->assertJsonCount(2, 'results');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'datamanager.googleapis.com') && $r['validateOnly'] === true);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'google-analytics.com/mp/collect'));

    // TikTok no tiene endpoint de validación: sin código de prueba no se manda nada.
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations/{$tiktok->publicId()}/test")->assertOk()
        ->assertJsonPath('ok', false)->assertJsonPath('results.0.message', fn ($m) => str_contains($m, 'código de prueba de TikTok'));
});

it('publica los píxeles del modo completo en /v1/s/config para el tracker', function () {
    [$site, $secret] = $this->connectedSite($this->ws);
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations", ['platform' => 'meta', 'mode' => 'full', 'credentials' => ['dataset_id' => '111', 'access_token' => 't']])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations", ['platform' => 'tiktok', 'mode' => 'server_only', 'credentials' => ['pixel_code' => 'C9', 'access_token' => 't']])->assertCreated();

    $this->signedJson('GET', '/v1/s/config', $site, $secret)->assertOk()
        ->assertJsonPath('measurement.mode', 'full')
        ->assertJsonPath('measurement.pixels', ['meta' => '111']);
});

it('muestra entregas, calidad de la señal y gasto', function () {
    $this->useIngestInfrastructure();
    $meta = $this->destination($this->ws, Platform::Meta);
    app(Ch::class)->insert('conversion_deliveries', [
        ['workspace_id' => $this->ws->ch_id, 'event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a', 'platform' => 'meta', 'status' => 'delivered', 'http_status' => 200, 'error' => '', 'match_keys' => ['email', 'phone', 'click_id'], 'attempts' => 1, 'delivered_at' => now()->utc()->format('Y-m-d H:i:s.v'), 'destination_id' => $meta->id, 'target' => 'meta_capi', 'conversion' => 'Purchase', 'source_event' => 'order.paid', 'error_code' => '', 'final' => 1, 'test' => 0],
        ['workspace_id' => $this->ws->ch_id, 'event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7b', 'platform' => 'meta', 'status' => 'failed', 'http_status' => 400, 'error' => 'El token de acceso de Meta no es válido', 'match_keys' => ['email'], 'attempts' => 1, 'delivered_at' => now()->utc()->format('Y-m-d H:i:s.v'), 'destination_id' => $meta->id, 'target' => 'meta_capi', 'conversion' => 'Purchase', 'source_event' => 'order.paid', 'error_code' => 'meta:190', 'final' => 1, 'test' => 0],
        ['workspace_id' => $this->ws->ch_id, 'event_id' => '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7c', 'platform' => 'meta', 'status' => 'retrying', 'http_status' => 503, 'error' => 'temporal', 'match_keys' => [], 'attempts' => 1, 'delivered_at' => now()->utc()->format('Y-m-d H:i:s.v'), 'destination_id' => $meta->id, 'target' => 'meta_capi', 'conversion' => 'ViewContent', 'source_event' => 'product.viewed', 'error_code' => 'meta:2', 'final' => 0, 'test' => 0],
    ]);
    $quality = $this->actingAs($this->owner)->getJson("{$this->base}/quality?days=7")->assertOk()->json('destinations.'.$meta->publicId());
    expect($quality)->toMatchArray(['total' => 2, 'delivered' => 1, 'failed' => 1, 'retrying' => 1, 'email_rate' => 1, 'phone_rate' => 0.5, 'click_id_rate' => 0.5])
        ->and($quality['last_errors'][0]['code'])->toBe('meta:190');

    $this->actingAs($this->owner)->getJson("{$this->base}/deliveries?status=failed")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.destination_id', $meta->publicId())->assertJsonPath('data.0.error', 'El token de acceso de Meta no es válido');

    app(CurrentWorkspace::class)->run($this->ws, fn () => AdSpendDaily::query()->insert([
        ['workspace_id' => $this->ws->id, 'platform' => 'meta', 'date' => now()->subDays(2)->toDateString(), 'campaign_id' => 'c1', 'campaign_name' => 'Nov', 'adset_id' => '', 'ad_id' => '', 'spend' => '1000.00', 'currency' => 'COP', 'impressions' => 10, 'clicks' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]));
    $this->actingAs($this->owner)->getJson("{$this->base}/spend")->assertOk()
        ->assertJsonPath('by_platform.0.spend', '1000.00')->assertJsonPath('by_campaign.0.campaign_name', 'Nov');
});

it('no mezcla destinos ni entregas entre espacios de trabajo', function () {
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra');
    $foreign = $this->destination($other, Platform::Meta);
    $this->actingAs($this->owner)->getJson("{$this->base}/destinations")->assertOk()->assertJsonCount(0, 'destinations');
    $this->actingAs($this->owner)->patchJson("{$this->base}/destinations/{$foreign->publicId()}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($this->owner)->postJson("{$this->base}/destinations/{$foreign->publicId()}/test")->assertNotFound();
});
