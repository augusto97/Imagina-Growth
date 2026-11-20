<?php

use App\Domain\Connections\Actions\CompleteAuthorization;
use App\Domain\Connections\Data\TokenSet;
use App\Domain\Connections\Enums\ConnectionProvider;
use App\Domain\Connections\Mail\ConnectionAlertMail;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Connections\Support\ConnectionFixtures;

/*
 * Avisos de conexiones por vencer o caídas (lote 4): correo a dueños y administradores 7 días y 1 día antes de que
 * venza un acceso y cuando vence o se invalida, una sola vez por cambio de estado, y el aviso con «Reconectar» del
 * Inicio y de Conexiones (GET connections/alerts). Todo con el reloj simulado y Http::fake.
 */

uses(ConnectionFixtures::class);

beforeEach(function () {
    $this->setUpConnections();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'America/Bogota'));
    $this->admin = $this->addMember($this->ws, WorkspaceRole::Admin);
    $this->marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    Mail::fake();
});

/** @param array<string, mixed> $attrs */
function alertConnection($test, array $attrs = []): OAuthConnection
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => OAuthConnection::query()->create($attrs + [
        'workspace_id' => $test->ws->id, 'provider' => 'meta', 'subject' => '', 'status' => 'active', 'account_name' => 'Pinturas La 14 SAS',
        'credentials' => ['access_token' => 'EAA-token-de-usuario'], 'scopes' => ['ads_management'], 'expires_at' => now()->addDays(10),
    ]));
}

function alertsSent(string $state): int
{
    return Mail::sent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => $m->state === $state)->count();
}

it('avisa 7 días y 1 día antes de que venza el token de usuario de Meta y cuando vence, una vez por estado', function () {
    Http::fake(['graph.test/v27.0/debug_token*' => Http::response($this->debugToken(['ads_management']))]);
    $connection = alertConnection($this);

    $this->artisan('igr:connections:check')->assertSuccessful();
    Mail::assertNothingSent();

    $this->travel(3)->days();  // quedan 7 días
    $this->artisan('igr:connections:check')->assertSuccessful();
    $this->artisan('igr:connections:check')->assertSuccessful();  // la noche siguiente no repite
    expect(alertsSent('expires_7d'))->toBe(2);
    Mail::assertSent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => $m->hasTo('duena@pinturas.co') && $m->expiresOn === '30 de noviembre'
        && str_contains($m->envelope()->subject ?? '', 'vence el 30 de noviembre') && str_ends_with($m->url, "/w/{$this->ws->publicId()}/conexiones"));
    Mail::assertSent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => $m->hasTo($this->admin->email));
    Mail::assertNotSent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => $m->hasTo($this->marketing->email));
    expect($connection->fresh()?->alert_state)->toBe('expires_7d');

    $alerts = $this->actingAs($this->marketing)->getJson("{$this->api}/connections/alerts")->assertOk()->json('data');
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toMatchArray(['kind' => 'connection', 'provider' => 'meta', 'subject' => 'workspace', 'state' => 'expires_7d', 'name' => 'Pinturas La 14 SAS']);

    $this->travel(6)->days();
    $this->travel(12)->hours();  // quedan 12 horas
    $this->artisan('igr:connections:check')->assertSuccessful();
    expect(alertsSent('expires_1d'))->toBe(2);

    // Venció: Meta dice que el token ya no sirve (is_valid = false) y la conexión queda vencida con el aviso.
    $this->travel(1)->days();
    $this->refake(['graph.test/v27.0/debug_token*' => Http::response(['data' => ['app_id' => '1234', 'is_valid' => false, 'error' => ['code' => 190, 'message' => 'Session has expired']]])]);
    $this->artisan('igr:connections:check')->assertSuccessful();
    $this->artisan('igr:connections:check')->assertSuccessful();
    expect(alertsSent('expired'))->toBe(2)->and($connection->fresh()?->status)->toBe('expired');
    expect($this->actingAs($this->owner)->getJson("{$this->api}/connections/alerts")->json('data.0'))->toMatchArray(['state' => 'expired']);

    // Al reconectar, los avisos empiezan de cero y el aviso del panel desaparece.
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(CompleteAuthorization::class)->save($this->ws->id, $this->owner->id, ConnectionProvider::Meta, '',
        new TokenSet(accessToken: 'EAA-nuevo', expiresAt: CarbonImmutable::now()->addDays(60), scopes: ['ads_management'])));
    expect($connection->fresh()?->alert_state)->toBeNull()
        ->and($this->actingAs($this->owner)->getJson("{$this->api}/connections/alerts")->json('data'))->toBe([]);
});

it('Google (se renueva solo) y el usuario del sistema de Meta no avisan por vencimiento; un invalid_grant avisa en el momento', function () {
    $google = alertConnection($this, ['provider' => 'google', 'credentials' => ['access_token' => 'ya29.x', 'refresh_token' => 'rt-google'], 'expires_at' => now()->addMinutes(30)]);
    alertConnection($this, ['provider' => 'tiktok', 'expires_at' => null]);
    alertConnection($this, ['provider' => 'meta', 'subject' => 'waba:2222', 'expires_at' => null]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.y', 'expires_in' => 3599, 'scope' => 'openid', 'token_type' => 'Bearer']),
        'graph.test/v27.0/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management'])),
        'business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
    ]);
    $this->artisan('igr:connections:check')->assertSuccessful();
    Mail::assertNothingSent();

    $this->refake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'graph.test/v27.0/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management'])),
        'business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
    ]);
    $this->actingAs($this->owner)->postJson("{$this->api}/connections/{$google->publicId()}/check")->assertUnprocessable()->assertJsonPath('reason', 'expired');
    expect(alertsSent('expired'))->toBe(2)->and($google->fresh()?->alert_state)->toBe('expired');
    Mail::assertSent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => $m->provider === 'Google' && str_contains($m->envelope()->subject ?? '', 'Vuelve a conectar Google'));

    // La revisión de la noche no lo repite.
    $this->artisan('igr:connections:check')->assertSuccessful();
    expect(alertsSent('expired'))->toBe(2);
});

it('una conexión desconectada a propósito no avisa y la de la agenda lleva a la agenda', function () {
    alertConnection($this, ['status' => 'revoked', 'credentials' => null, 'expires_at' => null]);
    alertConnection($this, ['provider' => 'google', 'subject' => 'staff:01JSTAFF0000000000000000AB', 'status' => 'expired', 'last_error' => 'La conexión con Google se cerró o venció.', 'expires_at' => null]);
    $this->artisan('igr:connections:check')->assertSuccessful();
    expect(alertsSent('expired'))->toBe(2);
    Mail::assertSent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => str_contains($m->url, '/agenda?pestana=personas') && $m->detail === 'La conexión con Google se cerró o venció.');
    expect($this->actingAs($this->owner)->getJson("{$this->api}/connections/alerts")->json('data'))->toHaveCount(1)
        ->and($this->actingAs($this->owner)->getJson("{$this->api}/connections/alerts")->json('data.0.subject'))->toBe('calendar');
});

it('cada espacio de trabajo ve y recibe solo sus avisos', function () {
    alertConnection($this, ['status' => 'expired', 'expires_at' => null]);
    $other = $this->userWith2fa(['email' => 'otro@ferreteria.co']);
    $wsB = $this->workspaceFor($other, 'Ferretería B');
    $this->artisan('igr:connections:check')->assertSuccessful();

    Mail::assertNotSent(ConnectionAlertMail::class, fn (ConnectionAlertMail $m) => $m->hasTo('otro@ferreteria.co'));
    $this->actingAs($other)->getJson("/api/w/{$wsB->publicId()}/connections/alerts")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->getJson("{$this->api}/connections/alerts")->assertNotFound();
});
