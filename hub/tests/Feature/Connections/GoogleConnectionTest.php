<?php

use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Measurement\Conversions\Platforms\DataManagerTarget;
use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\GoogleAccessToken;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Connections\Support\ConnectionFixtures;
use Tests\Feature\Connections\Support\GoogleScopes;
use Tests\Support\MeasurementFixtures;

/*
 * Conexiones con un clic — Google (docs/auditoria-2026-11.md §6): un solo OAuth de la plataforma para Google Ads
 * (Data Manager), Analytics y Calendar, con state de un solo uso ligado a la persona y PKCE.
 */

uses(ConnectionFixtures::class, MeasurementFixtures::class);

beforeEach(function () {
    $this->setUpConnections();
});

function googleWorkspaceConnection(): OAuthConnection
{
    return OAuthConnection::withoutWorkspaceScope()->where('provider', 'google')->where('subject', '')->firstOrFail();
}

it('autoriza con state y PKCE, guarda los tokens cifrados y vuelve al panel sin tokens en la URL', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.primero', 'refresh_token' => 'rt-google', 'expires_in' => 3599, 'scope' => GoogleScopes::ALL, 'id_token' => $this->idToken('duena@pinturas.co')]),
    ]);
    $url = (string) $this->actingAs($this->owner)->postJson("{$this->api}/connections/google/start", ['return' => 'medicion'])->assertOk()->json('url');
    $q = $this->urlQuery($url);
    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($q)->toMatchArray([
            'client_id' => 'google-client', 'redirect_uri' => 'https://growth.test/oauth/google/callback', 'response_type' => 'code',
            'access_type' => 'offline', 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'include_granted_scopes' => 'true',
        ])
        ->and($q['scope'])->toContain('https://www.googleapis.com/auth/datamanager')->toContain('https://www.googleapis.com/auth/adwords')->toContain('https://www.googleapis.com/auth/analytics.edit');

    $location = (string) $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $q['state'], 'code' => '4/c0de', 'scope' => 'x']))->assertRedirect()->headers->get('Location');
    $connection = googleWorkspaceConnection();
    expect($location)->toBe("https://growth.test/w/{$this->ws->publicId()}/medicion?conexion=google&estado=ok&id={$connection->publicId()}")
        ->and($location)->not->toContain('c0de')->not->toContain('ya29');

    // El verificador de PKCE que se mandó corresponde al desafío de la URL.
    Http::assertSent(function (Request $r) use ($q) {
        $verifier = $r['code_verifier'] ?? '';

        return str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['code'] === '4/c0de' && $r['redirect_uri'] === 'https://growth.test/oauth/google/callback'
            && rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=') === $q['code_challenge'];
    });
    expect($connection->secret('refresh_token'))->toBe('rt-google')->and($connection->account_name)->toBe('duena@pinturas.co')
        ->and((string) DB::table('oauth_connections')->value('credentials'))->not->toContain('rt-google')
        ->and(DB::table('audit_logs')->where('action', 'connection.connected')->exists())->toBeTrue();

    $list = $this->actingAs($this->owner)->getJson("{$this->api}/connections")->assertOk();
    $google = collect($list->json('providers'))->firstWhere('key', 'google');
    expect($google['configured'])->toBeTrue()->and($google['connections'][0])->toMatchArray(['status' => 'active', 'account_name' => 'duena@pinturas.co', 'kind' => 'workspace'])
        ->and($google['connections'][0]['uses'])->toBe(['conversions', 'ads', 'analytics'])
        ->and($list->getContent())->not->toContain('rt-google')->not->toContain('ya29');
});

it('el state no sirve inválido, reutilizado, vencido, de otro proveedor, sin sesión ni para otra persona', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => GoogleScopes::ALL])]);
    $start = fn () => $this->urlQuery((string) $this->actingAs($this->owner)->postJson("{$this->api}/connections/google/start")->json('url'))['state'];
    $invalid = 'https://growth.test/?conexion=invalida';

    $this->actingAs($this->owner)->get('/oauth/google/callback?state=basura&code=x')->assertRedirect($invalid);
    $this->actingAs($this->owner)->get('/oauth/google/callback?code=x')->assertRedirect($invalid);

    // Otra persona del mismo espacio de trabajo no puede terminar la autorización que empezó la dueña.
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, true);
    $state = $start();
    $this->actingAs($admin)->get('/oauth/google/callback?'.http_build_query(['state' => $state, 'code' => 'x']))->assertRedirect($invalid);
    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $state, 'code' => 'x']))->assertRedirect($invalid);  // ya se gastó

    // De Google no sirve en la vuelta de Meta.
    $state = $start();
    $this->actingAs($this->owner)->get('/oauth/meta/callback?'.http_build_query(['state' => $state, 'code' => 'x']))->assertRedirect($invalid);

    // Vence a los 10 minutos.
    $state = $start();
    $this->travel(11)->minutes();
    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $state, 'code' => 'x']))->assertRedirect($invalid);
    $this->travelBack();

    // Una vez bien, la segunda vez no.
    $state = $start();
    $ok = "https://growth.test/w/{$this->ws->publicId()}/conexiones?conexion=google&estado=ok";
    expect((string) $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $state, 'code' => 'x']))->headers->get('Location'))->toStartWith($ok);
    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $state, 'code' => 'x']))->assertRedirect($invalid);
    Http::assertSentCount(1);
    // Solo queda el que se probó en la vuelta de Meta: su firma no cuadra ahí y vence solo.
    expect(DB::table('oauth_states')->count())->toBe(1);
});

it('vuelve sin sesión al inicio, con «cancelado» si la persona no aceptó y sin permisos si dejó todo sin marcar', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'scope' => 'openid email'])]);
    $state = fn () => $this->urlQuery((string) $this->actingAs($this->owner)->postJson("{$this->api}/connections/google/start")->json('url'))['state'];
    $base = "https://growth.test/w/{$this->ws->publicId()}/conexiones?conexion=google";

    $s = $state();
    $this->app['auth']->forgetGuards();
    $this->get('/oauth/google/callback?'.http_build_query(['state' => $s, 'code' => 'x']))->assertRedirect('https://growth.test/?conexion=invalida');

    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $state(), 'error' => 'access_denied']))->assertRedirect($base.'&estado=cancelado');
    $this->actingAs($this->owner)->get('/oauth/google/callback?'.http_build_query(['state' => $state(), 'code' => 'x']))->assertRedirect($base.'&estado=error&motivo=scopes');
    expect(OAuthConnection::withoutWorkspaceScope()->count())->toBe(0);
});

it('sin la app de Google configurada explica qué falta y los campos manuales siguen', function () {
    config(['igr-connections.google.client_id' => '', 'igr-connections.meta.whatsapp_config_id' => '']);
    $google = collect($this->actingAs($this->owner)->getJson("{$this->api}/connections")->assertOk()->json('providers'))->firstWhere('key', 'google');
    expect($google)->toMatchArray(['configured' => false, 'missing' => ['google.client_id'], 'redirect_uri' => 'https://growth.test/oauth/google/callback']);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/google/start")->assertUnprocessable()->assertJsonPath('reason', 'not_configured');
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->assertUnprocessable()->assertJsonPath('missing', ['meta.es_config_id']);
    // El destino manual de siempre sigue funcionando.
    $this->actingAs($this->owner)->postJson("{$this->api}/measurement/destinations", ['platform' => 'google', 'credentials' => ['measurement_id' => 'G-1', 'api_secret' => 's']])->assertCreated();
});

it('solo dueño y administración conectan; el resto ve el estado', function () {
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->api}/connections")->assertOk();
    $this->actingAs($viewer)->postJson("{$this->api}/connections/google/start")->assertForbidden();
});

it('elige la cuenta de Ads y el flujo de Analytics, crea el secreto del Measurement Protocol y Data Manager usa la conexión', function () {
    $this->connectGoogle();
    $c = googleWorkspaceConnection();
    Http::fake([
        'googleads.googleapis.com/v26/customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/1234567890', 'customers/5550001111']]),
        'googleads.googleapis.com/v26/customers/1234567890/googleAds:search' => Http::sequence()
            ->push(['results' => [['customer' => ['id' => '1234567890', 'descriptiveName' => 'Pinturas', 'manager' => false, 'currencyCode' => 'COP']]]])
            ->push(['results' => [['conversionAction' => ['id' => '555', 'name' => 'Compra', 'category' => 'PURCHASE']]]]),
        'googleads.googleapis.com/v26/customers/5550001111/googleAds:search' => Http::sequence()
            ->push(['results' => [['customer' => ['id' => '5550001111', 'descriptiveName' => 'Agencia MCC', 'manager' => true]]]])
            ->push(['results' => [['customerClient' => ['id' => '7778889999', 'descriptiveName' => 'Cliente de la agencia', 'manager' => false]]]]),
        'analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => [['displayName' => 'Pinturas', 'propertySummaries' => [['property' => 'properties/321', 'displayName' => 'Tienda']]]]]),
        'analyticsadmin.googleapis.com/v1beta/properties/321/dataStreams/9/measurementProtocolSecrets' => Http::response(['name' => 'x', 'displayName' => 'Imagina Growth', 'secretValue' => 'mp-secreto']),
        'analyticsadmin.googleapis.com/v1beta/properties/321/dataStreams/9' => Http::response(['name' => 'properties/321/dataStreams/9', 'type' => 'WEB_DATA_STREAM', 'webStreamData' => ['measurementId' => 'G-TIENDA1']]),
        'analyticsadmin.googleapis.com/v1beta/properties/321/dataStreams*' => Http::response(['dataStreams' => [['name' => 'properties/321/dataStreams/9', 'type' => 'WEB_DATA_STREAM', 'displayName' => 'Sitio', 'webStreamData' => ['measurementId' => 'G-TIENDA1']]]]),
    ]);

    $assets = $this->actingAs($this->owner)->getJson("{$this->api}/connections/{$c->publicId()}/assets")->assertOk();
    expect($assets->json('ads_customers'))->toHaveCount(3)
        ->and(collect($assets->json('ads_customers'))->firstWhere('id', '7778889999'))->toMatchArray(['login_customer_id' => '5550001111', 'manager' => false])
        ->and($assets->json('properties.0'))->toMatchArray(['id' => 'properties/321', 'name' => 'Tienda']);
    // Sin developer token: desde el 9 de septiembre de 2026 Google ya no lo pide.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'listAccessibleCustomers') && $r->hasHeader('Authorization', 'Bearer ya29.primero') && ! $r->hasHeader('developer-token'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '5550001111/googleAds:search') && $r->hasHeader('login-customer-id', '5550001111'));

    expect($this->actingAs($this->owner)->getJson("{$this->api}/connections/{$c->publicId()}/assets?customer=1234567890")->assertOk()->json('conversion_actions.0'))->toMatchArray(['id' => '555', 'name' => 'Compra'])
        ->and($this->actingAs($this->owner)->getJson("{$this->api}/connections/{$c->publicId()}/assets?property=properties/321")->assertOk()->json('streams.0'))->toMatchArray(['measurement_id' => 'G-TIENDA1']);

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$c->publicId()}/measurement", [
        'ads_customer_id' => '123-456-7890', 'conversion_action_purchase' => '555', 'ga4_stream' => 'properties/321/dataStreams/9',
    ])->assertOk();
    expect($res->json('destination'))->toMatchArray(['platform' => 'google', 'status' => 'active', 'pixel_id' => 'G-TIENDA1'])
        ->and($res->json('destination.connection'))->toMatchArray(['provider' => 'google', 'status' => 'active'])
        ->and($res->json('destination.credentials.api_secret'))->toBe('••••reto')
        ->and($res->getContent())->not->toContain('mp-secreto');

    $destination = app(CurrentWorkspace::class)->run($this->ws, fn () => AdDestination::query()->firstOrFail());
    expect($destination->oauth_connection_id)->toBe($c->id)->and($destination->credential('oauth_refresh_token'))->toBeNull()
        ->and(app(DataManagerTarget::class)->supports($destination, 'order.paid'))->toBeTrue();
});

it('el token vencido se renueva solo (una vez) y si Google lo revocó la conexión queda vencida con el aviso', function () {
    $this->connectGoogle();
    $destination = $this->destination($this->ws, Platform::Google, ['config' => ['ads_customer_id' => '1234567890', 'conversion_action_purchase' => '555'], 'oauth_connection_id' => googleWorkspaceConnection()->id]);
    $tokens = app(GoogleAccessToken::class);
    expect($tokens->for($destination))->toBe('ya29.primero');

    $this->travel(2)->hours();
    $this->refake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.segundo', 'expires_in' => 3599, 'scope' => GoogleScopes::ALL])]);
    expect($tokens->for($destination->fresh()))->toBe('ya29.segundo')->and($tokens->for($destination->fresh()))->toBe('ya29.segundo');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'rt-google' && $r['client_id'] === 'google-client');
    expect(googleWorkspaceConnection()->secret('refresh_token'))->toBe('rt-google')->and(googleWorkspaceConnection()->refreshed_at)->not->toBeNull();

    $this->travel(2)->hours();
    $this->refake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    expect(fn () => $tokens->for($destination->fresh()))->toThrow(RuntimeException::class, 'Vuelve a conectarla');
    expect(googleWorkspaceConnection()->status)->toBe('expired')->and(googleWorkspaceConnection()->last_error)->toContain('Vuelve a conectarla');
});

it('desconectar revoca en Google, borra los tokens y el destino sin datos a mano queda en pausa', function () {
    $this->connectGoogle();
    $c = googleWorkspaceConnection();
    $destination = $this->destination($this->ws, Platform::Google, ['config' => ['ads_customer_id' => '1234567890'], 'oauth_connection_id' => $c->id]);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response([])]);

    $this->actingAs($this->owner)->deleteJson("{$this->api}/connections/{$c->publicId()}")->assertOk();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke') && $r['token'] === 'rt-google');
    $c->refresh();
    expect($c->status)->toBe('revoked')->and($c->credentials)->toBeNull()->and($c->revoked_at)->not->toBeNull();
    $destination->refresh();
    expect($destination->oauth_connection_id)->toBeNull()->and($destination->status)->toBe('paused')->and($destination->last_error)->toContain('Se desconectó');
    $this->actingAs($this->owner)->getJson("{$this->api}/connections/{$c->publicId()}/assets")->assertNotFound();
    expect(DB::table('audit_logs')->where('action', 'connection.disconnected')->exists())->toBeTrue();
});

it('la revisión diaria marca vencida la conexión que Google ya no renueva', function () {
    $this->connectGoogle();
    $this->refake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $this->artisan('igr:connections:check')->assertSuccessful();
    expect(googleWorkspaceConnection()->status)->toBe('expired')->and(googleWorkspaceConnection()->checked_at)->not->toBeNull();
});

it('una conexión de otro espacio de trabajo no se ve, no se usa y no presta sus tokens', function () {
    $this->connectGoogle();
    $mine = googleWorkspaceConnection();
    $other = $this->userWith2fa();
    $wsB = $this->workspaceFor($other, 'Ferretería B');
    $apiB = "/api/w/{$wsB->publicId()}";

    $this->actingAs($other)->getJson("{$this->api}/connections")->assertNotFound();
    $this->actingAs($other)->getJson("{$apiB}/connections/{$mine->publicId()}/assets")->assertNotFound();
    $this->actingAs($other)->deleteJson("{$apiB}/connections/{$mine->publicId()}")->assertNotFound();
    $this->actingAs($other)->postJson("{$apiB}/connections/{$mine->publicId()}/measurement", ['ads_customer_id' => '1'])->assertNotFound();
    expect(collect($this->actingAs($other)->getJson("{$apiB}/connections")->json('providers'))->flatMap(fn ($p) => $p['connections'])->all())->toBe([]);

    // Un destino de B que apunte a la conexión de A no la encuentra.
    $foreign = $this->destination($wsB, Platform::Google, ['config' => ['ads_customer_id' => '1'], 'oauth_connection_id' => $mine->id]);
    expect($foreign->linkedConnection())->toBeNull()->and($foreign->hasGoogleAuth())->toBeFalse();
    $meta = $this->destination($wsB, Platform::Meta, ['config' => ['dataset_id' => '1'], 'oauth_connection_id' => $mine->id]);
    expect($meta->credential('access_token'))->toBeNull();
    expect($mine->fresh()?->status)->toBe('active');
});

it('si la misma cuenta de Google está conectada en otro espacio de trabajo, desconectar aquí no la revoca en Google', function () {
    $this->connectGoogle();
    $mine = googleWorkspaceConnection();
    // La misma persona (mismo `sub` de Google) conectó también otra tienda de la agencia.
    $wsB = $this->workspaceFor($this->owner, 'Otra tienda');
    $this->api = "/api/w/{$wsB->publicId()}";
    $this->connectGoogle();
    $this->api = "/api/w/{$this->ws->publicId()}";

    $this->refake(['oauth2.googleapis.com/revoke' => Http::response([])]);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/connections/{$mine->publicId()}")->assertOk();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'revoke'));
    expect($mine->fresh()?->status)->toBe('revoked')
        ->and(OAuthConnection::withoutWorkspaceScope()->where('workspace_id', $wsB->id)->value('status'))->toBe('active');
});
