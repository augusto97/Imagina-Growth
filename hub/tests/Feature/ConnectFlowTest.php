<?php

use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Models\SiteConnectCode;
use App\Domain\Workspaces\Enums\WorkspaceRole;

function exchangePayload(string $code, array $extra = []): array
{
    return $extra + [
        'code' => $code,
        'site_url' => 'https://tienda.co',
        'rest_url' => 'https://tienda.co/wp-json/imagina-growth/v1/',
        'platform' => 'woocommerce',
        'name' => 'Pinturas La 14',
        'plugin_version' => '0.1.0', 'wp_version' => '6.8.1', 'wc_version' => '9.5.0', 'php_version' => '8.3.6',
        'timezone' => 'America/Bogota', 'currency' => 'COP',
        'capabilities' => ['hpos' => true, 'checkout_blocks' => true, 'forms' => ['cf7']],
    ];
}

it('conecta un sitio con el flujo del plugin y devuelve las claves (api-connector.md §3)', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $nonce = str_repeat('a1', 16);

    $res = $this->actingAs($owner)->postJson("/api/w/{$ws->publicId()}/sites/connect-codes", [
        'site_url' => 'https://www.tienda.co', 'nonce' => $nonce,
        'return_url' => 'https://tienda.co/wp-admin/admin.php?page=imagina-growth', 'platform' => 'woocommerce', 'name' => 'Pinturas',
    ])->assertCreated();
    $code = $res->json('code');
    expect($res->json('redirect_url'))->toStartWith('https://tienda.co/wp-admin/admin.php?page=imagina-growth&igr_code=IGR-');

    $keys = $this->postJson('/api/connect/exchange', exchangePayload($code, ['nonce' => $nonce]))->assertOk()->json();
    expect($keys['site_id'])->toMatch('/^st_[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($keys['public_key'])->toMatch('/^pk_[0-9a-z]{32}$/')
        ->and($keys['secret'])->toMatch('/^sk_/')
        ->and($keys['workspace']['id'])->toBe($ws->publicId())
        ->and($keys['endpoints']['ingest_server'])->toEndWith('/v1/s');

    $site = Site::withoutWorkspaceScope()->firstOrFail();
    expect($site->status)->toBe('connected')
        ->and($site->getRawOriginal('secret'))->not->toContain($keys['secret'])  // cifrado en reposo
        ->and($site->secret)->toBe($keys['secret'])
        ->and($site->capabilities['hpos'])->toBeTrue();

    // Un solo uso.
    $this->postJson('/api/connect/exchange', exchangePayload($code, ['nonce' => $nonce]))->assertUnprocessable()->assertJsonPath('error', 'invalid_code');

    // El secreto sirve para firmar el sitio de inmediato.
    $this->signedJson('GET', '/v1/s/config', $site, $keys['secret'])
        ->assertOk()->assertJsonPath('settings.paid_statuses', ['processing', 'completed']);
});

it('rechaza nonce distinto, otra URL, códigos vencidos y return_url de otro host', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $url = "/api/w/{$ws->publicId()}/sites/connect-codes";

    $this->actingAs($owner)->postJson($url, ['site_url' => 'https://tienda.co', 'nonce' => str_repeat('b', 32), 'return_url' => 'https://evil.co/x'])
        ->assertUnprocessable()->assertJsonValidationErrors('return_url');

    $code = $this->actingAs($owner)->postJson($url, ['site_url' => 'https://tienda.co', 'nonce' => str_repeat('b', 32), 'return_url' => 'https://tienda.co/wp-admin/'])->json('code');
    $this->postJson('/api/connect/exchange', exchangePayload($code, ['nonce' => str_repeat('c', 32)]))->assertJsonPath('error', 'nonce_mismatch');
    $this->postJson('/api/connect/exchange', exchangePayload($code, ['nonce' => str_repeat('b', 32), 'site_url' => 'https://otra.co']))->assertJsonPath('error', 'site_mismatch');

    $this->travel(11)->minutes();
    $this->postJson('/api/connect/exchange', exchangePayload($code, ['nonce' => str_repeat('b', 32)]))->assertJsonPath('error', 'invalid_code');
});

it('permite la conexión manual y reconecta el mismo host sin duplicar el sitio', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $url = "/api/w/{$ws->publicId()}/sites/connect-codes";

    $first = $this->actingAs($owner)->postJson($url, [])->assertCreated()->json('code');
    $a = $this->postJson('/api/connect/exchange', exchangePayload(strtolower($first)))->assertOk()->json();
    $second = $this->actingAs($owner)->postJson($url, [])->json('code');
    $b = $this->postJson('/api/connect/exchange', exchangePayload($second))->assertOk()->json();

    expect($b['site_id'])->toBe($a['site_id'])->and($b['secret'])->not->toBe($a['secret'])
        ->and(Site::withoutWorkspaceScope()->count())->toBe(1)
        ->and(SiteConnectCode::withoutWorkspaceScope()->whereNull('used_at')->count())->toBe(0);
});

it('solo owner y admin generan códigos de conexión', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $marketing = $this->addMember($ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->postJson("/api/w/{$ws->publicId()}/sites/connect-codes", [])->assertForbidden();
    $this->actingAs($marketing)->getJson("/api/w/{$ws->publicId()}/sites")->assertOk();
});

it('desconecta el sitio e invalida sus claves', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    [$site, $secret] = $this->connectedSite($ws);

    $this->actingAs($owner)->postJson("/api/w/{$ws->publicId()}/sites/{$site->publicId()}/disconnect")->assertOk();
    $this->signedJson('GET', '/v1/s/config', $site, $secret)->assertUnauthorized();
});

it('acepta una zona horaria como desfase de plugins viejos y usa la del espacio de trabajo', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $code = $this->actingAs($owner)->postJson("/api/w/{$ws->publicId()}/sites/connect-codes", [])->json('code');
    $this->postJson('/api/connect/exchange', exchangePayload($code, ['timezone' => '-05:00']))->assertOk();
    expect(Site::withoutWorkspaceScope()->value('timezone'))->toBe('America/Bogota');
});
