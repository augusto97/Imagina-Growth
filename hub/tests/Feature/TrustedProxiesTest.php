<?php

use App\Ingest\IngestStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * S8: el hub solo cree X-Forwarded-For de los proxies de IGR_TRUSTED_PROXIES (config/trustedproxy.php; por defecto
 * el Caddy de la máquina, 127.0.0.1 y ::1). Caddy resuelve la IP real y la manda ahí; lo que escribe un cliente que
 * se conecta directo no cuenta. La geo de la ingesta, los límites por IP y la auditoría usan $request->ip().
 */

beforeEach(function () {
    Route::get('/internal/pruebas/ip', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'port' => $request->getPort(),
        'base' => $request->getBaseUrl(),
    ]));
});

/** @param array<string, string> $server */
function clientIp(TestCase $test, array $server): array
{
    return $test->call('GET', '/internal/pruebas/ip', [], [], [], $server + ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
}

it('por defecto solo confía en el Caddy de la máquina', function () {
    expect(config('trustedproxy.proxies'))->toBe(['127.0.0.1', '::1'])
        ->and(clientIp($this, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3'])['ip'])->toBe('181.50.1.3')
        ->and(clientIp($this, ['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '2800:e2:1::7'])['ip'])->toBe('2800:e2:1::7')
        // Directo, sin pasar por Caddy: el X-Forwarded-For del cliente no cuenta.
        ->and(clientIp($this, ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3'])['ip'])->toBe('203.0.113.9')
        // Lo que el cliente antepone en la cadena tampoco: se toma la última IP que no es un proxy de confianza.
        ->and(clientIp($this, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 181.50.1.3'])['ip'])->toBe('181.50.1.3');
});

it('solo cree los encabezados que escribe Caddy', function () {
    $res = clientIp($this, [
        'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'growth.imagina.cloud', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3', 'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '8443', 'HTTP_X_FORWARDED_PREFIX' => '/otro',
    ]);

    expect($res)->toBe(['ip' => '181.50.1.3', 'secure' => true, 'port' => 443, 'base' => '']);
});

it('IGR_TRUSTED_PROXIES acepta una lista con rangos y «*» solo si se pide', function () {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    expect(clientIp($this, ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3'])['ip'])->toBe('181.50.1.3')
        ->and(clientIp($this, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3'])['ip'])->toBe('127.0.0.1');

    config(['trustedproxy.proxies' => '*']);
    expect(clientIp($this, ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3'])['ip'])->toBe('181.50.1.3');

    config(['trustedproxy.proxies' => []]);
    expect(clientIp($this, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3'])['ip'])->toBe('127.0.0.1');
});

it('el archivo de configuración lee IGR_TRUSTED_PROXIES', function (?string $env, array|string $expected) {
    $previous = getenv('IGR_TRUSTED_PROXIES');
    $env === null ? putenv('IGR_TRUSTED_PROXIES') : putenv('IGR_TRUSTED_PROXIES='.$env);
    try {
        expect((require base_path('config/trustedproxy.php'))['proxies'])->toBe($expected);
    } finally {
        $previous === false ? putenv('IGR_TRUSTED_PROXIES') : putenv('IGR_TRUSTED_PROXIES='.$previous);
    }
})->with([
    [null, ['127.0.0.1', '::1']],
    ['*', '*'],
    [' 10.0.0.0/8 , 192.168.1.4,, ', ['10.0.0.0/8', '192.168.1.4']],
    ['', []],
]);

it('la ingesta del navegador usa la IP real para la geo y el límite por IP', function () {
    $this->useIngestInfrastructure();
    $ws = $this->workspaceFor($this->userWith2fa());
    [$site] = $this->connectedSite($ws);
    $event = fn () => $this->envelope($site, 'page.viewed', ['page_type' => 'home'], ['source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'], 'identity' => ['anonymous_id' => 'c3b0f4a2']]);
    $post = fn (array $server) => $this->call('POST', '/v1/b', [], [], [], $server + ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], (string) json_encode(['k' => $site->public_key, 'events' => [$event()]]))->assertStatus(202);
    $transitIps = function (): array {
        $stream = app(IngestStream::class);
        $ips = [];
        for ($i = 0; $i < $stream->shards(); $i++) {
            foreach ((array) $stream->connection()->command('xrange', [$stream->key($i), '-', '+']) as $fields) {
                $ips[] = $fields['ip'] ?? null;
            }
        }

        return $ips;
    };

    $post(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3']);
    $post(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3']);

    expect($transitIps())->toBe(['181.50.1.3', '198.51.100.7'])
        ->and(RateLimiter::attempts('igr-b-ip:181.50.1.3'))->toBe(1)
        ->and(RateLimiter::attempts('igr-b-ip:198.51.100.7'))->toBe(1)
        ->and(RateLimiter::attempts('igr-b-ip:127.0.0.1'))->toBe(0);
});

it('la auditoría guarda el hash de la IP real', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    // La auditoría solo toma la IP en una petición web (en consola no hay visitante).
    (fn () => $this->isRunningInConsole = false)->call($this->app);
    $hash = fn (string $ip) => hash_hmac('sha256', $ip, (string) config('app.key'));
    $create = fn (array $server) => $this->actingAs($owner)->call('POST', "/api/w/{$ws->publicId()}/sites/connect-codes", [], [], [], $server + ['HTTP_ACCEPT' => 'application/json'])->assertCreated();

    $create(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3']);
    $create(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '181.50.1.3']);

    expect(DB::table('audit_logs')->where('action', 'site.connect_code_created')->orderBy('id')->pluck('ip_hash')->all())
        ->toBe([$hash('181.50.1.3'), $hash('198.51.100.7')]);
});
