<?php

use App\Domain\Inbox\Models\PushSubscription;
use App\Domain\Inbox\Push\LogPushNotifier;
use App\Domain\Inbox\Push\PushNotifier;
use App\Domain\Inbox\Push\WebPushNotifier;
use App\Support\Tenancy\CurrentWorkspace;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

function pushTestSubscription($test, string $endpoint): PushSubscription
{
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return app(CurrentWorkspace::class)->run($test->ws, fn () => PushSubscription::query()->create([
        'workspace_id' => $test->ws->id, 'user_id' => $test->owner->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        // Una llave P-256 válida (la de VAPID sirve) y 16 bytes de auth: lo que da el navegador.
        'keys' => ['p256dh' => VAPID::createVapidKeys()['publicKey'], 'auth' => $b64(random_bytes(16))],
    ]));
}

it('envía el push con VAPID, sin el texto del mensaje, y borra las suscripciones vencidas', function () {
    $keys = VAPID::createVapidKeys();
    config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);
    $ok = pushTestSubscription($this, 'https://push.example.com/ok');
    pushTestSubscription($this, 'https://push.example.com/gone');
    $http = new class implements ClientInterface
    {
        /** @var list<RequestInterface> */
        public array $requests = [];

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;

            return new Response(str_ends_with((string) $request->getUri(), '/gone') ? 410 : 201);
        }
    };
    $client = new WebPush(['VAPID' => ['subject' => 'mailto:soporte@imagina.cloud', 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], [], $http, logger: app('log')->channel());

    $sent = (new WebPushNotifier($client))->notify($this->ws->id, $this->owner->id, 'Nuevo mensaje', '/w/x/bandeja/cnv_1');
    expect($sent)->toBe(1)
        ->and($http->requests)->toHaveCount(2)
        ->and($http->requests[0]->getHeaderLine('Authorization'))->toStartWith('vapid t=')
        ->and($http->requests[0]->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and(PushSubscription::withoutWorkspaceScope()->count())->toBe(1)
        ->and($ok->fresh()->last_used_at)->not->toBeNull();
});

it('sin llaves VAPID la bandeja solo registra el aviso en el log', function () {
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);
    app()->forgetInstance(PushNotifier::class);
    expect(app(PushNotifier::class))->toBeInstanceOf(LogPushNotifier::class);
    $keys = VAPID::createVapidKeys();
    config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);
    expect(app(PushNotifier::class))->toBeInstanceOf(WebPushNotifier::class);
});
