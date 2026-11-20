<?php

use App\Domain\Billing\Platform\InstallationDoctor;
use App\Domain\Sites\Actions\RotateSiteSecret;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Support\SiteSignatures;
use App\Support\Hmac\RequestSignature;
use App\Support\Hmac\Signature;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * S14: la firma v2 cubre el método, la ruta, la consulta, la hora, un nonce y el hash del cuerpo
 * (docs/api-connector.md §2). El hub acepta v1 de los plugins anteriores a la 0.1.4 mientras
 * `igr.signature.accept_v1` siga prendido, y firma los comandos en v2 solo si el plugin la entiende.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

/** Petición al hub con una cabecera de firma ya armada. */
function sendWithSignature(TestCase $test, string $method, string $uri, Site $site, string $header, string $body = ''): TestResponse
{
    return $test->call($method, $uri, [], [], [], [
        'HTTP_X_IGR_SITE' => $site->publicId(),
        'HTTP_X_IGR_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

it('acepta la ingesta firmada en v2 y rechaza la misma petición repetida', function () {
    $body = (string) gzencode((string) json_encode(['sent_at' => now()->toIso8601String(), 'events' => [
        $this->envelope($this->site, 'site.heartbeat', ['plugin_version' => '0.1.4']),
    ]]));
    $header = RequestSignature::signV2($this->secret, 'POST', '/v1/s', $body);
    $send = fn () => $this->call('POST', '/v1/s', [], [], [], $this->transformHeadersToServerVars([
        'X-IGR-Site' => $this->site->publicId(), 'X-IGR-Signature' => $header, 'CONTENT_TYPE' => 'application/json', 'Content-Encoding' => 'gzip',
    ]), $body);

    $send()->assertStatus(202)->assertJsonPath('accepted', 1);
    // Mismo nonce dentro de la ventana: repetición.
    $send()->assertUnauthorized()->assertJson(['error' => 'signature']);
});

it('una firma v2 de una ruta no sirve para otra ni con otra consulta', function () {
    $config = RequestSignature::signV2($this->secret, 'GET', '/v1/s/config', '');
    sendWithSignature($this, 'GET', '/v1/s/catalog/hashes', $this->site, $config)->assertUnauthorized();

    $products = RequestSignature::signV2($this->secret, 'GET', '/v1/s/catalog/hashes?type=product&limit=10', '');
    sendWithSignature($this, 'GET', '/v1/s/catalog/hashes?type=category&limit=10', $this->site, $products)->assertUnauthorized();
    // El orden y la codificación de la consulta no importan.
    sendWithSignature($this, 'GET', '/v1/s/catalog/hashes?limit=10&type=%70roduct', $this->site, $products)->assertOk();

    // Otro método con el mismo cuerpo vacío: tampoco.
    $get = RequestSignature::signV2($this->secret, 'GET', '/v1/s/backfill', '');
    sendWithSignature($this, 'PUT', '/v1/s/backfill', $this->site, $get)->assertUnauthorized();

    // La misma firma en otro endpoint firmado (saldo, privacidad): tampoco.
    $privacy = (string) json_encode(['kind' => 'access', 'email' => 'ana@ejemplo.com']);
    $header = RequestSignature::signV2($this->secret, 'POST', '/v1/s/privacy/requests', $privacy);
    sendWithSignature($this, 'POST', '/v1/s/wallet/redeem', $this->site, $header, $privacy)->assertUnauthorized();
});

it('acepta v1 de los plugins anteriores y la deja de aceptar con el ajuste', function () {
    $v1 = Signature::sign($this->secret, '');
    sendWithSignature($this, 'GET', '/v1/s/config', $this->site, $v1)->assertOk();
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'site.heartbeat', [])], version: 1)->assertStatus(202);

    config(['igr.signature.accept_v1' => false]);
    sendWithSignature($this, 'GET', '/v1/s/config', $this->site, Signature::sign($this->secret, ''))->assertUnauthorized();
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'site.heartbeat', [])], version: 1)->assertUnauthorized();
    // La v2 sigue entrando.
    $this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->assertOk()->assertJsonPath('signature.versions', [2]);
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'site.heartbeat', [])])->assertStatus(202);
});

it('la configuración anuncia las versiones y guarda la versión que informa el plugin', function () {
    $this->site->forceFill(['plugin_version' => '0.1.3'])->save();
    expect(SiteSignatures::versionFor($this->site->refresh()))->toBe(1);

    $this->signedJson('GET', '/v1/s/config?plugin_version=0.1.4', $this->site, $this->secret)
        ->assertOk()
        ->assertJsonPath('signature.versions', [1, 2])
        ->assertJsonPath('plugin.latest_version', config('igr.plugin.latest_version'));
    expect($this->site->refresh()->getAttribute('plugin_version'))->toBe('0.1.4')
        ->and(SiteSignatures::versionFor($this->site))->toBe(2);

    // Un valor que no es una versión no se guarda.
    $this->signedJson('GET', '/v1/s/config?plugin_version=9.9.9<x>', $this->site, $this->secret)->assertOk();
    expect($this->site->refresh()->getAttribute('plugin_version'))->toBe('0.1.4');
});

it('el canje anuncia las versiones de la firma', function () {
    $code = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/sites/connect-codes", [])->assertCreated()->json('code');

    $keys = $this->postJson('/api/connect/exchange', [
        'code' => $code, 'site_url' => 'https://otra.co', 'rest_url' => 'https://otra.co/wp-json/imagina-growth/v1/',
        'platform' => 'woocommerce', 'plugin_version' => '0.1.4',
    ])->assertOk()->json();

    expect($keys['signature'])->toBe(['versions' => [1, 2]]);
});

it('firma los comandos en v1 al plugin viejo y en v2 al que la entiende', function () {
    Http::fake(['tienda.co/*' => Http::response(['ok' => true, 'data' => ['outbox' => []]])]);
    $url = 'https://tienda.co/wp-json/imagina-growth/v1/commands';
    $send = fn () => app(CurrentWorkspace::class)->run($this->ws, fn () => app(SendSiteCommand::class)->handle($this->site->refresh(), 'site.diagnostics'));

    foreach ([[null, 1], ['0.1.3', 1], ['0.1.4', 2], ['0.2.0', 2]] as [$version, $expected]) {
        $this->site->forceFill(['plugin_version' => $version])->save();
        $send();
        /** @var ClientRequest $request */
        $request = Http::recorded()->last()[0];
        $header = $request->header('X-IGR-Signature')[0] ?? null;
        $body = $request->body();
        $verified = RequestSignature::verify($header, 'POST', $url, $body, [$this->secret], $expected === 1);
        expect($request->url())->toBe($url)
            ->and($verified?->version)->toBe($expected, (string) $version);
        if ($expected === 2) {
            // El nonce del cuerpo es el de la firma, y la firma no sirve para otra ruta.
            expect(json_decode($body, true)['nonce'])->toBe($verified?->nonce)
                ->and(RequestSignature::verify($header, 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/otro', $body, [$this->secret]))->toBeNull();
        }
    }

    // La rotación firma con el secreto anterior, también en v2.
    $old = $this->secret;
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(RotateSiteSecret::class)->handle($this->site->refresh()));
    /** @var ClientRequest $rotate */
    $rotate = Http::recorded()->last()[0];
    expect(RequestSignature::verify($rotate->header('X-IGR-Signature')[0] ?? null, 'POST', $url, $rotate->body(), [$old], false)?->version)->toBe(2);

    // Con la v1 apagada, todos reciben v2.
    config(['igr.signature.accept_v1' => false]);
    $this->site->forceFill(['plugin_version' => '0.1.3'])->save();
    expect(SiteSignatures::versionFor($this->site->refresh()))->toBe(2);
});

it('el diagnóstico dice cuántos sitios firman con v1 y cuándo se puede apagar', function () {
    $this->site->forceFill(['plugin_version' => '0.1.3'])->save();
    $check = fn () => collect(app(InstallationDoctor::class)->run())->firstWhere('key', 'data.signature');

    expect($check()->status)->toBe('info')->and($check()->items)->toBe(['tienda.co (0.1.3)']);

    config(['igr.signature.accept_v1' => false]);
    expect($check()->status)->toBe('error');

    $this->site->forceFill(['plugin_version' => '0.1.4'])->save();
    expect($check()->status)->toBe('ok');
    config(['igr.signature.accept_v1' => true]);
    expect($check()->status)->toBe('info')->and($check()->items)->toBe([]);
});
