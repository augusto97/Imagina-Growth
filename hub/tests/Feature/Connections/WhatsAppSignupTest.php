<?php

use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Connections\Support\ConnectionFixtures;

/*
 * WhatsApp con un clic (Embedded Signup v5, docs/auditoria-2026-11.md §6): canje del código, revisión del token
 * con debug_token, suscripción de la app a la WABA, registro del número (o coexistencia con la app de WhatsApp
 * Business) y plantillas sincronizadas. Sin app real de Meta: todo con Http::fake.
 */

uses(ConnectionFixtures::class);

beforeEach(function () {
    $this->setUpConnections();
    $this->syncRequests = 0;
});

/** @param list<array<string, mixed>> $phones */
function waSignupFakes(object $test, array $phones, array $granular = ['whatsapp_business_management' => ['333333'], 'whatsapp_business_messaging' => ['333333']], string $appId = '1234'): void
{
    Http::fake([
        'graph.test/v27.0/oauth/access_token*' => Http::response(['access_token' => 'EAAbiz-negocio', 'token_type' => 'bearer']),
        'graph.test/v27.0/debug_token*' => Http::response($test->debugToken(['whatsapp_business_management', 'whatsapp_business_messaging', 'business_management'], $granular, $appId)),
        'graph.test/v27.0/me?*' => Http::response(['id' => '777', 'name' => 'Pinturas La 14 SAS']),
        'graph.test/v27.0/333333/subscribed_apps' => Http::response(['success' => true]),
        'graph.test/v27.0/333333/phone_numbers*' => Http::response(['data' => $phones]),
        'graph.test/v27.0/333333/message_templates*' => Http::response(['data' => [['id' => '55', 'name' => 'bienvenida', 'language' => 'es', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hola']]]]]),
        'graph.test/v27.0/444444/register' => Http::response(['success' => true]),
        // La copia se pide con la cuenta ya creada y activa: los webhooks del historial se buscan por el número.
        'graph.test/v27.0/444444/smb_app_data' => fn () => Http::response(ChannelAccount::withoutWorkspaceScope()->where('external_id', '444444')->where('status', 'active')->exists()
            ? ['messaging_product' => 'whatsapp', 'request_id' => 'req-'.(++$test->syncRequests)] : ['error' => ['message' => 'La cuenta todavía no existe']], 200),
        'graph.test/v27.0/444444*' => Http::response(['display_phone_number' => '+57 301 4444444', 'verified_name' => 'Pinturas La 14', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K']),
        'graph.test/v27.0/444445*' => Http::response(['display_phone_number' => '+57 301 4444445', 'verified_name' => 'Pinturas Norte', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_250']),
        'graph.test/v27.0/444445/register' => Http::response(['success' => true]),
    ]);
}

function waPhone(string $id, bool $coexistence = false): array
{
    return ['id' => $id, 'display_phone_number' => '+57 301 '.$id, 'verified_name' => 'Pinturas La 14', 'quality_rating' => 'GREEN', 'is_on_biz_app' => $coexistence, 'platform_type' => 'CLOUD_API'];
}

it('entrega a la SPA el state y los datos públicos de la ventana de Meta (Embedded Signup v5)', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->assertOk();
    expect($res->json())->toMatchArray(['app_id' => '1234', 'config_id' => 'cfg-wa', 'graph_version' => 'v27.0', 'version' => 'v5'])
        ->and($res->json('state'))->toBeString()->and($res->getContent())->not->toContain('meta-secret');

    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing, true);
    $this->actingAs($marketing)->postJson("{$this->api}/connections/meta_whatsapp/start")->assertForbidden();
});

it('FINISH: canjea el código, revisa el token, suscribe la app, registra el número y sincroniza las plantillas', function () {
    waSignupFakes($this, [waPhone('444444')]);
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $body = ['state' => $state, 'code' => 'AQB-codigo', 'event' => 'FINISH', 'waba_id' => '333333', 'phone_number_id' => '444444', 'business_id' => '999111'];

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", $body)->assertCreated();
    expect($res->json('data'))->toMatchArray(['provider' => 'meta_cloud', 'status' => 'active', 'identity' => '+57 301 4444444', 'quality_rating' => 'GREEN', 'is_default' => true])
        ->and($res->json('connection'))->toMatchArray(['provider' => 'meta_whatsapp', 'kind' => 'whatsapp', 'status' => 'active', 'account_name' => 'Pinturas La 14 SAS'])
        ->and($res->json('connection.whatsapp'))->toBe(['waba_id' => '333333', 'coexistence' => false])
        ->and($res->getContent())->not->toContain('EAAbiz');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token') && $r['code'] === 'AQB-codigo' && $r['client_secret'] === 'meta-secret' && ! isset($r['redirect_uri']));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'debug_token') && $r->hasHeader('Authorization', 'Bearer 1234|meta-secret'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '333333/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer EAAbiz-negocio'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '444444/register') && $r['messaging_product'] === 'whatsapp' && preg_match('/^\d{6}$/', (string) $r['pin']) === 1);

    $account = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->where('external_id', '444444')->firstOrFail());
    $connection = OAuthConnection::withoutWorkspaceScope()->where('provider', 'meta_whatsapp')->firstOrFail();
    expect($account->oauth_connection_id)->toBe($connection->id)->and($connection->subject)->toBe('waba:333333')
        ->and($account->config)->not->toHaveKey('access_token')->and($account->credential('access_token'))->toBe('EAAbiz-negocio')
        ->and(preg_match('/^\d{6}$/', (string) $account->config['two_step_pin']))->toBe(1)
        ->and(WaTemplate::withoutWorkspaceScope()->where('name', 'bienvenida')->exists())->toBeTrue()
        ->and((string) DB::table('oauth_connections')->value('credentials'))->not->toContain('EAAbiz');

    // El state ya se usó: repetir la llamada no vuelve a canjear nada.
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", $body)->assertUnprocessable()->assertJsonPath('reason', 'state');
});

it('coexistencia: el número sigue en la app de WhatsApp Business, no se registra y se piden los contactos y el historial', function () {
    waSignupFakes($this, [waPhone('444444', true)]);
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", [
        'state' => $state, 'code' => 'AQB', 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'waba_id' => '333333',
    ])->assertCreated();

    expect($res->json('data.status'))->toBe('active')->and($res->json('connection.whatsapp.coexistence'))->toBeTrue();
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/register'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '444444/smb_app_data') && $r['sync_type'] === 'smb_app_state_sync');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '444444/smb_app_data') && $r['sync_type'] === 'history');
    $account = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->firstOrFail());
    expect($account->config['coexistence'])->toBeTrue()->and($account->config['synced'])->toHaveKeys(['smb_app_state_sync', 'history'])
        ->and($account->config['sync_requests'])->toBe(['smb_app_state_sync' => 'req-1', 'history' => 'req-2'])
        ->and($account->config)->not->toHaveKey('two_step_pin');

    // Volver a elegir el número no pide otra copia (Meta la da una sola vez por conexión).
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$res->json('connection.id')}/whatsapp-number", ['phone_number_id' => '444444'])->assertSuccessful();
    expect($this->syncRequests)->toBe(2);
});

it('varios números: devuelve la lista y conecta el que la persona elige', function () {
    waSignupFakes($this, [waPhone('444444'), waPhone('444445')]);
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", ['state' => $state, 'code' => 'AQB', 'event' => 'FINISH', 'waba_id' => '333333'])->assertOk();
    expect($res->json('data'))->toBeNull()->and($res->json('phones'))->toHaveCount(2);
    $id = $res->json('connection.id');

    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$id}/whatsapp-number", ['phone_number_id' => '999999'])->assertUnprocessable()->assertJsonPath('reason', 'asset');
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$id}/whatsapp-number", ['phone_number_id' => '444445'])->assertCreated()->assertJsonPath('data.identity', '+57 301 4444445');
});

it('si Meta no activa el número queda en error con el motivo, y se puede reintentar', function () {
    waSignupFakes($this, [waPhone('444444')]);
    $this->refake([
        'graph.test/v27.0/444444/register' => Http::sequence()
            ->push(['error' => ['message' => 'Two step verification PIN mismatch', 'code' => 133005]], 400)
            ->push(['success' => true]),
        'graph.test/v27.0/oauth/access_token*' => Http::response(['access_token' => 'EAAbiz-negocio']),
        'graph.test/v27.0/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management', 'whatsapp_business_messaging'], ['whatsapp_business_management' => ['333333']])),
        'graph.test/v27.0/me?*' => Http::response(['id' => '777', 'name' => 'Pinturas']),
        'graph.test/v27.0/333333/subscribed_apps' => Http::response(['success' => true]),
        'graph.test/v27.0/333333/phone_numbers*' => Http::response(['data' => [waPhone('444444')]]),
        'graph.test/v27.0/333333/message_templates*' => Http::response(['data' => []]),
        'graph.test/v27.0/444444*' => Http::response(['display_phone_number' => '+57 301 4444444', 'verified_name' => 'Pinturas', 'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K']),
    ]);
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", ['state' => $state, 'code' => 'AQB', 'event' => 'FINISH', 'waba_id' => '333333', 'phone_number_id' => '444444'])->assertCreated();
    expect($res->json('data.status'))->toBe('error')->and($res->json('data.last_error'))->toContain('PIN')->and($res->json('data.is_default'))->toBeFalse();
    $pin = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->firstOrFail()->config['two_step_pin']);

    $retry = $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$res->json('connection.id')}/whatsapp-number", ['phone_number_id' => '444444'])->assertCreated();
    expect($retry->json('data'))->toMatchArray(['status' => 'active', 'last_error' => null, 'id' => $res->json('data.id')]);
    // Reintenta con el mismo PIN y sin crear otra cuenta.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '444444/register') && $r['pin'] === $pin);
    expect(ChannelAccount::withoutWorkspaceScope()->count())->toBe(1);
});

it('rechaza una cuenta de WhatsApp que no se autorizó, permisos incompletos y tokens de otra app', function () {
    $start = fn () => $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $body = fn () => ['state' => $start(), 'code' => 'AQB', 'event' => 'FINISH', 'waba_id' => '333333', 'phone_number_id' => '444444'];

    waSignupFakes($this, [waPhone('444444')], ['whatsapp_business_management' => ['888888'], 'whatsapp_business_messaging' => ['888888']]);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", $body())->assertUnprocessable()->assertJsonPath('reason', 'asset');

    $this->refake(['graph.test/v27.0/oauth/access_token*' => Http::response(['access_token' => 'EAA']), 'graph.test/v27.0/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management'])), 'graph.test/v27.0/me?*' => Http::response(['name' => 'x'])]);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", $body())->assertUnprocessable()->assertJsonPath('reason', 'scopes');

    $this->refake(['graph.test/v27.0/oauth/access_token*' => Http::response(['access_token' => 'EAA']), 'graph.test/v27.0/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management', 'whatsapp_business_messaging'], [], '9999'))]);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", $body())->assertUnprocessable()->assertJsonPath('reason', 'expired');

    $this->refake(['graph.test/v27.0/oauth/access_token*' => Http::response(['error' => ['message' => 'This authorization code has expired.', 'code' => 100]], 400)]);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", $body())->assertUnprocessable()->assertJsonPath('reason', 'exchange');
    expect($res->json('message'))->toContain('expired');

    expect(OAuthConnection::withoutWorkspaceScope()->count())->toBe(0)->and(ChannelAccount::withoutWorkspaceScope()->count())->toBe(0);
});

it('cancelar o un error en la ventana queda en la auditoría y el state no se puede reutilizar', function () {
    Http::fake();
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/abandon", ['state' => $state, 'event' => 'CANCEL', 'current_step' => 'PHONE_NUMBER_SETUP'])->assertOk();
    expect(json_decode((string) DB::table('audit_logs')->where('action', 'connection.whatsapp_cancelled')->value('changes'), true))->toBe(['step' => 'PHONE_NUMBER_SETUP']);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", ['state' => $state, 'code' => 'x', 'event' => 'FINISH', 'waba_id' => '333333'])->assertUnprocessable()->assertJsonPath('reason', 'state');

    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/abandon", ['state' => $state, 'event' => 'ERROR', 'error_code' => '524126', 'session_id' => 'f34b51dab5e0498'])->assertOk();
    expect(DB::table('audit_logs')->where('action', 'connection.whatsapp_failed')->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('el state de un espacio de trabajo no sirve en otro', function () {
    waSignupFakes($this, [waPhone('444444')]);
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $wsB = $this->workspaceFor($this->owner, 'Otra tienda');
    $this->actingAs($this->owner)->postJson("/api/w/{$wsB->publicId()}/connections/meta_whatsapp/complete", ['state' => $state, 'code' => 'AQB', 'event' => 'FINISH', 'waba_id' => '333333'])
        ->assertUnprocessable()->assertJsonPath('reason', 'state');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token'));
});

it('desconectar el canal revoca la conexión de WhatsApp y deja de recibir sus webhooks', function () {
    waSignupFakes($this, [waPhone('444444')]);
    $state = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/start")->json('state');
    $id = $this->actingAs($this->owner)->postJson("{$this->api}/connections/meta_whatsapp/complete", ['state' => $state, 'code' => 'AQB', 'event' => 'FINISH', 'waba_id' => '333333'])->assertCreated()->json('data.id');
    $this->refake(['graph.test/v27.0/333333/subscribed_apps' => Http::response(['success' => true])]);

    $this->actingAs($this->owner)->deleteJson("{$this->api}/channels/{$id}")->assertOk();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '333333/subscribed_apps'));
    $connection = OAuthConnection::withoutWorkspaceScope()->firstOrFail();
    expect($connection->status)->toBe('revoked')->and($connection->credentials)->toBeNull();
    $account = ChannelAccount::withoutWorkspaceScope()->firstOrFail();
    expect($account->status)->toBe('disconnected')->and($account->credential('access_token'))->toBeNull();
});
