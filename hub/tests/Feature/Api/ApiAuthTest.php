<?php

use App\Domain\Api\Actions\IssueApiToken;
use App\Domain\Api\Models\ApiClient;
use App\Domain\Api\Support\ApiMeter;
use App\Domain\Contacts\Models\Contact;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/ApiHelpers.php';

// API pública v1 (M19): token por espacio de trabajo, alcances, plan, límite y forma de los errores.
beforeEach(function () {
    apiEntitlements();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
});

it('sin token o con un token inválido responde 401 con la forma de error de la API', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertHeader('WWW-Authenticate', 'Bearer');
    $this->getJson('/api/v1/me', apiHeaders('igr_99|inventado'))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->getJson('/api/v1/me', apiHeaders('otra-cosa'))->assertUnauthorized();
});

it('con un token válido devuelve el espacio de trabajo, los alcances y el límite, y cuenta la petición', function () {
    [$token, $client] = apiToken($this->ws, ['contacts:read', 'inventado']);
    expect($token)->toStartWith('igr_')->and($client->scopes)->toBe(['contacts:read'])
        ->and($client->token_prefix)->toBe(mb_substr($token, 0, 12));

    $this->getJson('/api/v1/me', apiHeaders($token))->assertOk()
        ->assertJsonPath('workspace.id', $this->ws->publicId())
        ->assertJsonPath('client.scopes', ['contacts:read'])
        ->assertJsonPath('rate_limit.per_minute', 120)
        ->assertHeader('X-RateLimit-Limit', '120');

    expect(ApiMeter::count($this->ws))->toBe(1)
        ->and(ApiClient::withoutWorkspaceScope()->find($client->id)->last_used_at)->not->toBeNull();
});

it('un token revocado deja de funcionar al instante', function () {
    [$token, $client] = apiToken($this->ws, ['contacts:read']);
    $this->getJson('/api/v1/me', apiHeaders($token))->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(IssueApiToken::class)->revoke($client));

    $this->getJson('/api/v1/me', apiHeaders($token))->assertUnauthorized();
});

it('sin el alcance responde 403 missing_scope', function () {
    [$token] = apiToken($this->ws, ['orders:read']);
    $this->getJson('/api/v1/contacts', apiHeaders($token))->assertForbidden()
        ->assertJsonPath('error.code', 'missing_scope')
        ->assertJsonPath('error.message', 'Este token no tiene el alcance «contacts:read».');
});

it('si el plan no incluye la API responde 402', function () {
    [$token] = apiToken($this->ws, ['contacts:read']);
    apiEntitlements(deny: ['api']);
    $this->getJson('/api/v1/me', apiHeaders($token))->assertStatus(402)->assertJsonPath('error.code', 'plan_required');
});

it('aplica el límite por minuto del plan con 429 y Retry-After', function () {
    apiEntitlements(perMinute: 2);
    [$token, $client] = apiToken($this->ws, ['contacts:read']);
    RateLimiter::clear('api:rate:'.$client->id);
    $this->getJson('/api/v1/me', apiHeaders($token))->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
    $this->getJson('/api/v1/me', apiHeaders($token))->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $this->getJson('/api/v1/me', apiHeaders($token))->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
});

it('los errores de validación y de ruta usan la misma forma con campos', function () {
    [$token] = apiToken($this->ws, ['contacts:write', 'contacts:read']);
    $this->postJson('/api/v1/contacts', ['first_name' => 'Ana'], apiHeaders($token))->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email', 'phone']]]);
    $this->getJson('/api/v1/no-existe', apiHeaders($token))->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/contacts/ct_01JD0000000000000000000000', apiHeaders($token))->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('un token de un espacio de trabajo nunca ve datos de otro', function () {
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    $foreign = app(CurrentWorkspace::class)->run($other, fn () => Contact::query()->create(['workspace_id' => $other->id, 'first_name' => 'Beto']));
    $mine = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana']));
    [$token] = apiToken($this->ws, ['contacts:read']);

    $this->getJson('/api/v1/contacts/'.$foreign->publicId(), apiHeaders($token))->assertNotFound();
    $ids = collect($this->getJson('/api/v1/contacts', apiHeaders($token))->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$mine->publicId()]);
});
