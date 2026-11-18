<?php

use App\Domain\Api\Models\ApiClient;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;

require_once __DIR__.'/ApiHelpers.php';

// Pantalla «API e integraciones» (M19): tokens que se muestran una vez, revocar y presets del ecosistema.
beforeEach(function () {
    apiEntitlements(deny: ['mcp'], perMinute: 300);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->api = fn (string $path) => '/api/w/'.$this->ws->publicId().'/'.ltrim($path, '/');
});

it('muestra el plan, el límite, el uso, los alcances y el ecosistema', function () {
    $this->actingAs($this->owner)->getJson(($this->api)('integrations'))->assertOk()
        ->assertJsonPath('plan', ['api' => true, 'mcp' => false])
        ->assertJsonPath('rate_limit_per_minute', 300)
        ->assertJsonPath('requests_this_month', 0)
        ->assertJsonPath('ecosystem.0.key', 'crm')
        ->assertJsonStructure(['base_url', 'mcp_url', 'docs_url', 'scopes', 'events' => ['orders', 'leads']]);
    $this->actingAs($this->addMember($this->ws, WorkspaceRole::Marketing, true))->getJson(($this->api)('integrations'))->assertForbidden();
});

it('crea un token que se ve una sola vez, lo lista sin el secreto y lo revoca', function () {
    $res = $this->actingAs($this->owner)->postJson(($this->api)('integrations/tokens'), ['name' => 'Make', 'scopes' => ['contacts:read', 'leads:write']])->assertCreated();
    $token = $res->json('token');
    expect($token)->toStartWith('igr_');
    $this->getJson('/api/v1/me', apiHeaders($token))->assertOk();

    $list = $this->actingAs($this->owner)->getJson(($this->api)('integrations/tokens'))->assertOk();
    expect(json_encode($list->json()))->not->toContain(explode('|', $token)[1])
        ->and($list->json('data.0.token_prefix'))->toBe(mb_substr($token, 0, 12));

    $this->actingAs($this->owner)->deleteJson(($this->api)('integrations/tokens/'.$res->json('data.id')))->assertOk()->assertJsonPath('data.revoked_at', fn ($v) => $v !== null);
    $this->getJson('/api/v1/me', apiHeaders($token))->assertUnauthorized();
    $this->actingAs($this->owner)->postJson(($this->api)('integrations/tokens'), ['name' => 'X', 'scopes' => ['todo']])->assertUnprocessable();
});

it('conecta Imagina CRM con su token y su webhook con datos personales', function () {
    $res = $this->actingAs($this->owner)->postJson(($this->api)('integrations/ecosystem/crm'), ['webhook_url' => 'https://crm.imagina.test/hooks/growth'])->assertCreated();
    expect($res->json('token'))->toStartWith('igr_')->and($res->json('secret'))->toStartWith('whsec_')
        ->and($res->json('data.scopes'))->toContain('leads:write', 'contacts:pii')
        ->and($res->json('webhook.include_pii'))->toBeTrue()->and($res->json('webhook.preset'))->toBe('crm');
    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(ApiClient::query()->sole()->preset)->toBe('crm')->and(WebhookSubscription::query()->sole()->events)->toContain('lead.*');
    });
    $this->actingAs($this->owner)->getJson(($this->api)('integrations'))->assertJsonPath('ecosystem.0.connected', true);
    $this->actingAs($this->owner)->postJson(($this->api)('integrations/ecosystem/inventado'))->assertNotFound();
});

it('la documentación OpenAPI la ve solo quien administra la API', function () {
    $this->get('/api/docs')->assertForbidden();
    $marketing = $this->userWith2fa();
    $this->ws->users()->attach($marketing->id, ['role' => WorkspaceRole::Marketing->value]);
    $this->actingAs($marketing)->get('/api/docs')->assertForbidden();
    $this->actingAs($this->owner)->get('/api/docs')->assertOk();
    $this->actingAs($this->owner)->get('/api/docs.json')->assertOk()->assertJsonPath('info.title', config('app.name'))->assertJsonStructure(['paths' => ['/contacts', '/leads']]);
});
