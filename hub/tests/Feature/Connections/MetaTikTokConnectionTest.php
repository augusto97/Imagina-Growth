<?php

use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Measurement\Conversions\Platforms\MetaCapiTarget;
use App\Domain\Measurement\Conversions\Platforms\TikTokTarget;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Connections\Support\ConnectionFixtures;
use Tests\Support\MeasurementFixtures;

/*
 * Meta para anuncios, audiencias, Messenger e Instagram (Login for Business) y TikTok for Business
 * (docs/auditoria-2026-11.md §6), con el puente hacia los destinos de Medición y los canales.
 */

uses(ConnectionFixtures::class, MeasurementFixtures::class);

beforeEach(function () {
    $this->setUpConnections();
});

function connectMeta(object $test): OAuthConnection
{
    Http::fake([
        'graph.test/v27.0/oauth/access_token*' => Http::response(['access_token' => 'EAAsys-usuario-sistema', 'token_type' => 'bearer']),
        'graph.test/v27.0/debug_token*' => Http::response($test->debugToken(
            ['ads_management', 'ads_read', 'business_management', 'pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'instagram_basic', 'instagram_manage_messages'],
            ['ads_management' => ['998877'], 'pages_messaging' => ['5555555']],
        )),
        'graph.test/v27.0/me?*' => Http::response(['id' => '777', 'name' => 'Pinturas La 14 SAS']),
    ]);
    $url = (string) $test->actingAs($test->owner)->postJson("{$test->api}/connections/meta/start", ['return' => 'medicion'])->assertOk()->json('url');
    $q = $test->urlQuery($url);
    expect($url)->toStartWith('https://www.facebook.test/v27.0/dialog/oauth?')
        ->and($q)->toMatchArray(['client_id' => '1234', 'config_id' => 'cfg-login', 'response_type' => 'code', 'override_default_response_type' => 'true', 'redirect_uri' => 'https://growth.test/oauth/meta/callback']);
    $location = (string) $test->actingAs($test->owner)->get('/oauth/meta/callback?'.http_build_query(['state' => $q['state'], 'code' => 'AQD-codigo']))->headers->get('Location');
    expect($location)->toStartWith("https://growth.test/w/{$test->ws->publicId()}/medicion?conexion=meta&estado=ok&id=oac_");
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token') && $r['redirect_uri'] === 'https://growth.test/oauth/meta/callback');

    return OAuthConnection::withoutWorkspaceScope()->where('provider', 'meta')->firstOrFail();
}

it('Meta: Login for Business, elige el conjunto de datos y la cuenta, y la Conversions API usa el token de la conexión', function () {
    $connection = connectMeta($this);
    expect($connection->account_name)->toBe('Pinturas La 14 SAS')->and($connection->expires_at)->toBeNull()
        ->and($connection->asset('granted'))->toMatchArray(['ads_management' => ['998877']]);

    // Un destino de Meta pegado a mano antes: la conexión lo reemplaza, sin borrar su token.
    $manual = $this->destination($this->ws, Platform::Meta, ['config' => ['dataset_id' => '123', 'access_token' => 'EAA-pegado-a-mano']]);
    Http::fake([
        'graph.test/v27.0/me/adaccounts*' => Http::response(['data' => [['id' => 'act_998877', 'account_id' => '998877', 'name' => 'Pinturas Ads', 'currency' => 'COP']]]),
        'graph.test/v27.0/act_998877/adspixels*' => Http::response(['data' => [['id' => '11112222', 'name' => 'Píxel de la tienda']]]),
        'graph.test/v27.0/me/accounts*' => Http::response(['data' => [['id' => '5555555', 'name' => 'Pinturas La 14', 'instagram_business_account' => ['id' => '17840001', 'username' => 'pinturasla14']]]]),
        'graph.test/v27.0/11112222*' => Http::response(['id' => '11112222', 'name' => 'Píxel de la tienda']),
    ]);
    $assets = $this->actingAs($this->owner)->getJson("{$this->api}/connections/{$connection->publicId()}/assets")->assertOk();
    expect($assets->json('ad_accounts.0'))->toMatchArray(['id' => '998877', 'name' => 'Pinturas Ads'])
        ->and($assets->json('datasets.0'))->toMatchArray(['id' => '11112222', 'ad_account_id' => '998877'])
        ->and($assets->json('pages.0.instagram'))->toMatchArray(['id' => '17840001', 'username' => 'pinturasla14'])
        ->and($assets->getContent())->not->toContain('EAAsys');

    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/measurement", ['dataset_id' => '11112222', 'ad_account_id' => '4444'])
        ->assertUnprocessable()->assertJsonPath('reason', 'asset');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/measurement", ['dataset_id' => '11112222', 'ad_account_id' => '998877'])->assertOk();
    expect($res->json('destination'))->toMatchArray(['id' => $manual->publicId(), 'pixel_id' => '11112222', 'status' => 'active'])
        ->and($res->json('destination.connection.provider'))->toBe('meta');

    $destination = $manual->fresh();
    expect($destination->credential('access_token'))->toBe('EAAsys-usuario-sistema')->and($destination->credential('ad_account_id'))->toBe('998877')
        ->and(app(MetaCapiTarget::class)->supports($destination, 'order.paid'))->toBeTrue();
    Http::fake(['graph.test/v27.0/11112222/events' => Http::response(['events_received' => 1])]);
    app(MetaCapiTarget::class)->send($destination, ['data' => []]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '11112222/events') && $r->hasHeader('Authorization', 'Bearer EAAsys-usuario-sistema'));

    // Al desconectar vuelve al token pegado a mano.
    Http::fake(['graph.test/v27.0/me/permissions' => Http::response(['success' => true])]);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/connections/{$connection->publicId()}")->assertOk();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'me/permissions'));
    $destination = $destination->fresh();
    expect($destination->oauth_connection_id)->toBeNull()->and($destination->status)->toBe('active')->and($destination->credential('access_token'))->toBe('EAA-pegado-a-mano');
});

it('Meta: conecta Messenger e Instagram de una página autorizada con su token de página', function () {
    $connection = connectMeta($this);
    Http::fake([
        'graph.test/v27.0/5555555/subscribed_apps' => Http::response(['success' => true]),
        'graph.test/v27.0/5555555*' => Http::response(['id' => '5555555', 'name' => 'Pinturas La 14', 'access_token' => 'EAApagina', 'instagram_business_account' => ['id' => '17840001', 'username' => 'pinturasla14']]),
    ]);
    $ig = $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/social", ['channel' => 'instagram', 'page_id' => '5555555'])->assertCreated();
    expect($ig->json('data'))->toMatchArray(['channel' => 'instagram', 'provider' => 'meta_instagram', 'identity' => '@pinturasla14', 'status' => 'active'])
        ->and($ig->getContent())->not->toContain('EAApagina');
    $messenger = $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/social", ['channel' => 'messenger', 'page_id' => '5555555'])->assertCreated();
    expect($messenger->json('data'))->toMatchArray(['channel' => 'messenger', 'provider' => 'meta_messenger', 'identity' => 'Pinturas La 14']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '5555555?') && $r->hasHeader('Authorization', 'Bearer EAAsys-usuario-sistema'));

    $accounts = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->orderBy('channel')->get());
    expect($accounts)->toHaveCount(2)->and($accounts->pluck('oauth_connection_id')->unique()->all())->toBe([$connection->id])
        ->and($accounts->first()?->config['connected_via'])->toBe('oauth');

    // Volver a conectar la misma página actualiza la cuenta, no crea otra; otra página no autorizada no pasa.
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/social", ['channel' => 'messenger', 'page_id' => '5555555'])->assertCreated();
    expect(ChannelAccount::withoutWorkspaceScope()->count())->toBe(2);

    $list = collect($this->actingAs($this->owner)->getJson("{$this->api}/connections")->json('providers'))->firstWhere('key', 'meta');
    expect($list['connections'][0]['used_by']['channels'])->toHaveCount(2)->and($list['connections'][0]['uses'])->toContain('messenger')->toContain('instagram');

    // Desconectar Meta desconecta los canales que dependían de ella.
    Http::fake(['graph.test/v27.0/me/permissions' => Http::response(['success' => true])]);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/connections/{$connection->publicId()}")->assertOk();
    expect(ChannelAccount::withoutWorkspaceScope()->pluck('status')->unique()->all())->toBe(['disconnected'])
        ->and(ChannelAccount::withoutWorkspaceScope()->first()?->credential('page_access_token'))->toBeNull();
});

it('Meta: un token revocado se detecta al revisar y la conexión queda vencida', function () {
    $connection = connectMeta($this);
    $this->refake(['graph.test/v27.0/debug_token*' => Http::response(['data' => ['app_id' => '1234', 'is_valid' => false, 'error' => ['code' => 190, 'message' => 'Error validating access token']]])]);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/check")->assertUnprocessable()->assertJsonPath('reason', 'expired');
    expect($connection->fresh()?->status)->toBe('expired');
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/measurement", ['dataset_id' => '1'])->assertUnprocessable()->assertJsonPath('reason', 'expired');
});

it('TikTok: autoriza con auth_code, elige anunciante y píxel, la Events API usa el token y desconectar lo revoca', function () {
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/oauth2/access_token/' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['access_token' => 'tt-largo-plazo', 'advertiser_ids' => ['7001'], 'scope' => [4, 5]]]),
        'business-api.tiktok.com/open_api/v1.3/oauth2/advertiser/get/*' => Http::response(['code' => 0, 'data' => ['list' => [['advertiser_id' => '7001', 'advertiser_name' => 'Pinturas TikTok']]]]),
        'business-api.tiktok.com/open_api/v1.3/pixel/list/*' => Http::response(['code' => 0, 'data' => ['pixels' => [['pixel_code' => 'CABC123DEF456', 'pixel_name' => 'Tienda']]]]),
        'business-api.tiktok.com/open_api/v1.3/oauth2/revoke_token/' => Http::response(['code' => 0, 'message' => 'OK']),
    ]);
    $url = (string) $this->actingAs($this->owner)->postJson("{$this->api}/connections/tiktok/start")->assertOk()->json('url');
    $q = $this->urlQuery($url);
    expect($url)->toStartWith('https://business-api.tiktok.com/portal/auth?')->and($q)->toMatchArray(['app_id' => 'tt-app', 'redirect_uri' => 'https://growth.test/oauth/tiktok/callback']);
    $location = (string) $this->actingAs($this->owner)->get('/oauth/tiktok/callback?'.http_build_query(['auth_code' => 'tt-code', 'state' => $q['state']]))->headers->get('Location');
    expect($location)->toContain('conexion=tiktok&estado=ok');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'oauth2/access_token/') && $r['auth_code'] === 'tt-code' && $r['secret'] === 'tt-secret' && $r['app_id'] === 'tt-app');
    $connection = OAuthConnection::withoutWorkspaceScope()->where('provider', 'tiktok')->firstOrFail();

    expect($this->actingAs($this->owner)->getJson("{$this->api}/connections/{$connection->publicId()}/assets")->assertOk()->json('advertisers.0'))->toBe(['id' => '7001', 'name' => 'Pinturas TikTok'])
        ->and($this->actingAs($this->owner)->getJson("{$this->api}/connections/{$connection->publicId()}/assets?advertiser=7001")->assertOk()->json('pixels.0.id'))->toBe('CABC123DEF456');
    $this->actingAs($this->owner)->getJson("{$this->api}/connections/{$connection->publicId()}/assets?advertiser=7002")->assertUnprocessable()->assertJsonPath('reason', 'asset');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'advertiser/get') && $r->hasHeader('Access-Token', 'tt-largo-plazo'));

    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$connection->publicId()}/measurement", ['advertiser_id' => '7001', 'pixel_code' => 'CABC123DEF456'])->assertOk()
        ->assertJsonPath('destination.platform', 'tiktok')->assertJsonPath('destination.pixel_id', 'CABC123DEF456');
    $destination = app(CurrentWorkspace::class)->run($this->ws, fn () => AdDestination::query()->where('platform', 'tiktok')->firstOrFail());
    expect($destination->credential('access_token'))->toBe('tt-largo-plazo')->and(app(TikTokTarget::class)->supports($destination, 'order.paid'))->toBeTrue();

    $this->actingAs($this->owner)->deleteJson("{$this->api}/connections/{$connection->publicId()}")->assertOk();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'oauth2/revoke_token/') && $r['access_token'] === 'tt-largo-plazo');
    expect($destination->fresh()?->status)->toBe('paused');
});
