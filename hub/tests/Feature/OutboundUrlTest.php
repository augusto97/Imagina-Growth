<?php

use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\Drivers\SmtpDriver;
use App\Domain\Messaging\Channels\Drivers\ZenderDriver;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Support\Net\HostResolver;
use App\Support\Net\SafeHttp;
use App\Support\Net\SafeUrl;
use App\Support\Net\UnsafeUrl;
use Illuminate\Support\Facades\Http;

/*
 * S5 (SSRF): las URL y hosts de salida que eligen los clientes (webhooks, rest_url del sitio, Zender, SMTP) no
 * pueden apuntar a redes internas, ni en IPv4 ni en IPv6, y el DNS se resuelve al conectar: se conecta a la IP
 * revisada, así un cambio de DNS entre la revisión y la conexión (DNS rebinding) no lleva a una red interna.
 */

/** DNS simulado: cada llamada a resolve() devuelve la siguiente respuesta de la lista del host (la última se repite). */
function fakeDns(array $answers): object
{
    $resolver = new class($answers) extends HostResolver
    {
        /** @var array<string, int> */
        public array $calls = [];

        /** @param array<string, list<list<string>>|list<string>> $answers */
        public function __construct(private array $answers) {}

        public function resolve(string $host): array
        {
            $n = $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;
            $answer = $this->answers[$host] ?? [];
            if ($answer !== [] && is_array($answer[0])) {
                return $answer[min($n, count($answer)) - 1];
            }

            return $answer;
        }
    };
    app()->instance(HostResolver::class, $resolver);

    return $resolver;
}

beforeEach(function () {
    config(['igr.webhooks.allow_private_urls' => false]);
    Http::fake();
});

it('al guardar rechaza http, localhost, nombres internos e IP privadas en IPv4 e IPv6', function (string $url) {
    expect(SafeUrl::problem($url))->not->toBeNull();
})->with([
    'http://hooks.ejemplo.com/x', 'https://localhost/x', 'https://localhost./x', 'https://api.localhost/x', 'https://nas.local/x',
    'https://db.internal/x', 'https://intranet/x', 'https://10.0.0.5/x', 'https://127.0.0.1:8080/x', 'https://169.254.169.254/latest/meta-data',
    'https://[::1]/x', 'https://[fd00::1]/x', 'https://[fe80::1]/x', 'https://[::ffff:127.0.0.1]/x', 'https://[::ffff:a9fe:a9fe]/x',
    'https://2130706433/x', 'https://usuario:clave@hooks.ejemplo.com/x', 'ftp://hooks.ejemplo.com/x', 'no es una url',
]);

it('al guardar acepta HTTPS públicas, también IPv6 públicas', function (string $url) {
    expect(SafeUrl::problem($url))->toBeNull();
})->with(['https://hooks.ejemplo.com/igr', 'https://203.0.114.10/igr', 'https://[2606:4700:4700::1111]/igr']);

it('http se permite donde no hace falta HTTPS (sitio, Zender), pero nunca hacia redes internas', function () {
    expect(SafeUrl::problem('http://tienda.co/wp-json/', httpsOnly: false))->toBeNull()
        ->and(SafeUrl::problem('http://192.168.1.20/wp-json/', httpsOnly: false))->not->toBeNull()
        ->and(SafeUrl::hostProblem('smtp.gmail.com'))->toBeNull()
        ->and(SafeUrl::hostProblem('10.0.0.25'))->not->toBeNull()
        ->and(SafeUrl::hostProblem('[::1]'))->not->toBeNull();
});

it('al conectar resuelve el DNS y rechaza si alguna IP es interna (A o AAAA) o si no resuelve', function () {
    fakeDns([
        'mixto.ejemplo.com' => ['93.184.216.34', '10.0.0.1'],
        'v6local.ejemplo.com' => ['93.184.216.34', 'fd00::5'],
        'mapeado.ejemplo.com' => ['::ffff:127.0.0.1'],
        'nube.ejemplo.com' => ['169.254.169.254'],
    ]);
    foreach (['mixto', 'v6local', 'mapeado', 'nube'] as $name) {
        expect(fn () => SafeHttp::options("https://{$name}.ejemplo.com/x"))->toThrow(UnsafeUrl::class);
    }
    try {
        SafeHttp::options('https://no-existe.ejemplo.com/x');
        $this->fail('Debía fallar');
    } catch (UnsafeUrl $e) {
        expect($e->reason)->toBe('dns_unresolved');
    }
    expect(SafeUrl::resolvesPublic('https://mixto.ejemplo.com/x'))->toBeFalse();
});

it('conecta a la IP revisada (sin volver a resolver), sin redirecciones y solo por HTTP o HTTPS', function () {
    // DNS rebinding: la primera respuesta es pública y la siguiente apunta a la red interna.
    $dns = fakeDns(['rebind.ejemplo.com' => [['93.184.216.34'], ['127.0.0.1']], 'v6.ejemplo.com' => ['2606:4700::6810:84e5']]);
    $options = SafeHttp::options('https://rebind.ejemplo.com:8443/hook');
    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['rebind.ejemplo.com:8443:93.184.216.34'])
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTP | CURLPROTO_HTTPS)
        ->and($dns->calls['rebind.ejemplo.com'])->toBe(1);
    // Una segunda conexión vuelve a resolver y esta vez ya no pasa.
    expect(fn () => SafeHttp::options('https://rebind.ejemplo.com:8443/hook'))->toThrow(UnsafeUrl::class);

    expect(SafeHttp::options('https://v6.ejemplo.com/hook')['curl'][CURLOPT_RESOLVE])->toBe(['v6.ejemplo.com:443:[2606:4700::6810:84e5]'])
        ->and(SafeHttp::options('http://v6.ejemplo.com/x', httpsOnly: false)['curl'][CURLOPT_RESOLVE])->toBe(['v6.ejemplo.com:80:[2606:4700::6810:84e5]'])
        // Una IP literal pública no necesita fijarse.
        ->and(SafeHttp::options('https://93.184.216.34/x')['curl'])->not->toHaveKey(CURLOPT_RESOLVE);
    expect(fn () => SafeHttp::options('http://v6.ejemplo.com/x'))->toThrow(UnsafeUrl::class);
});

it('los comandos al sitio no salen si rest_url resuelve a la red interna', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    [$site] = $this->connectedSite($ws, 'tienda.co');
    fakeDns(['tienda.co' => ['127.0.0.1']]);
    expect(fn () => app(SendSiteCommand::class)->handle($site, 'product.lookup', ['ids' => ['1']]))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('el handshake del plugin rechaza un sitio o un rest_url internos', function () {
    $this->postJson('/api/connect/exchange', ['code' => 'IGR-ABCD-EFGH', 'site_url' => 'https://tienda.co', 'rest_url' => 'http://10.0.0.8/wp-json/imagina-growth/v1/'])
        ->assertStatus(422)->assertJsonPath('error', 'url_not_public')->assertJsonPath('field', 'rest_url');
    $this->postJson('/api/connect/exchange', ['code' => 'IGR-ABCD-EFGH', 'site_url' => 'http://[::1]:8080'])
        ->assertStatus(422)->assertJsonPath('field', 'site_url');
});

it('Zender y SMTP: no se guardan ni se conectan a redes internas', function () {
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    $api = "/api/w/{$ws->publicId()}/channels";
    $this->actingAs($owner)->postJson("{$api}/email", ['provider' => 'smtp', 'from_email' => 'hola@tienda.co', 'from_name' => 'Tienda', 'host' => '192.168.1.5', 'port' => 587])
        ->assertUnprocessable()->assertJsonValidationErrors('host');
    $this->actingAs($owner)->postJson("{$api}/email", ['provider' => 'smtp', 'from_email' => 'hola@tienda.co', 'from_name' => 'Tienda', 'host' => 'localhost', 'port' => 25])
        ->assertUnprocessable()->assertJsonValidationErrors('host');
    $this->actingAs($owner)->postJson("{$api}/whatsapp/zender", ['base_url' => 'https://[fd00::10]', 'api_secret' => 's', 'account_id' => '1', 'phone' => '3001234567', 'accept_risk' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('base_url');

    // Guardados antes con un nombre público que hoy resuelve a la red interna: el envío falla sin reintentar.
    fakeDns(['smtp.cliente.co' => ['10.1.1.1'], 'zender.cliente.co' => ['::1']]);
    $message = new OutgoingMessage('msg_1', 'ana@ejemplo.com', false, 'Hola', '<p>Hola</p>', 'Hola');
    $smtp = (new ChannelAccount)->forceFill(['channel' => 'email', 'provider' => 'smtp', 'config' => ['host' => 'smtp.cliente.co', 'port' => 587, 'from_email' => 'hola@cliente.co', 'from_name' => 'Cliente']]);
    $result = (new SmtpDriver)->send($smtp, $message);
    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('url_not_public')->and($result->retryable)->toBeFalse();

    $zender = (new ChannelAccount)->forceFill(['channel' => 'whatsapp', 'provider' => 'zender', 'config' => ['base_url' => 'https://zender.cliente.co', 'api_secret' => 's', 'account_id' => '1']]);
    $result = (new ZenderDriver)->send($zender, new OutgoingMessage('msg_2', '+573001234567', false, text: 'Hola'));
    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('url_not_public');
    Http::assertNothingSent();
});
