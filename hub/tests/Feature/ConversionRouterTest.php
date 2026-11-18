<?php

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Jobs\AttributeOrder;
use App\Domain\Measurement\Jobs\DeliverConversion;
use App\Domain\Measurement\Listeners\ConversionRouter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MeasurementFixtures;

uses(MeasurementFixtures::class);

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

function measurementLiveOrderPaid($test, array $context = [], string $type = 'server'): array
{
    return $test->envelope($test->site, 'order.paid', ['order' => $test->orderSnapshot()], [
        'source' => ['type' => $type],
        'identity' => ['email' => 'ana@ejemplo.com', 'anonymous_id' => 'anon-1'],
        'context' => array_replace_recursive(['consent' => ['analytics' => true, 'marketing' => true, 'source' => 'complianz'], 'ad_cookies' => ['fbp' => 'fb.1.1.2']], $context),
    ]);
}

it('encola la conversión de cada destino activo en su cola con el event_id del evento', function () {
    Queue::fake();
    $this->destination($this->ws, Platform::Meta);
    $this->destination($this->ws, Platform::TikTok);
    $event = measurementLiveOrderPaid($this);
    $this->postSigned($this->site, $this->secret, [$event]);
    $this->runWorker();

    Queue::assertPushedOn('conversions:meta', DeliverConversion::class, fn (DeliverConversion $j) => $j->payload['event_id'] === $event['event_id'] && $j->payload['event_name'] === 'Purchase');
    Queue::assertPushedOn('conversions:tiktok', DeliverConversion::class, fn (DeliverConversion $j) => $j->payload['event_id'] === $event['event_id']);
    Queue::assertPushed(AttributeOrder::class);
    expect(DeliverConversion::class)->toImplement(ShouldBeEncrypted::class);
});

it('respeta el consentimiento: en modo estricto exige marketing = true', function () {
    Queue::fake();
    $this->ws->forceFill(['settings' => ['consent_mode' => 'strict'] + $this->ws->settings])->save();
    $this->destination($this->ws, Platform::Meta);
    $this->postSigned($this->site, $this->secret, [measurementLiveOrderPaid($this, ['consent' => ['marketing' => false]])]);
    $this->runWorker();
    $noContext = measurementLiveOrderPaid($this);
    unset($noContext['context']['consent']);
    $this->postSigned($this->site, $this->secret, [array_replace($noContext, ['event_id' => (string) Str::uuid7()])]);
    $this->runWorker();
    Queue::assertNotPushed(DeliverConversion::class);

    // Modo informado: sin consentimiento explícito sí se envía; con «no» explícito, no.
    $this->ws->forceFill(['settings' => ['consent_mode' => 'informed'] + $this->ws->settings])->save();
    $this->postSigned($this->site, $this->secret, [array_replace($noContext, ['event_id' => (string) Str::uuid7()])]);
    $this->runWorker();
    Queue::assertPushed(DeliverConversion::class, 1);
});

it('según el sitio (por defecto): estricto si el evento viene de un sitio con banner, informado si no', function () {
    Queue::fake();
    expect($this->ws->setting('consent_mode'))->toBe('auto');
    $this->destination($this->ws, Platform::Meta);
    $send = function (array $consent) {
        $event = measurementLiveOrderPaid($this);
        $event['event_id'] = (string) Str::uuid7();
        $event['context']['consent'] = $consent;
        $this->postSigned($this->site, $this->secret, [$event]);
        $this->runWorker();
    };
    // Con banner y sin decisión del visitante: estricto, no se envía.
    $send(['analytics' => false, 'marketing' => false, 'source' => 'complianz']);
    // Sin banner y sin rechazo explícito… el plugin manda marketing=false por defecto: tampoco.
    $send(['analytics' => true, 'marketing' => false, 'source' => 'none']);
    Queue::assertNotPushed(DeliverConversion::class);
    // Con banner y marketing aceptado: sí.
    $send(['analytics' => true, 'marketing' => true, 'source' => 'complianz']);
    Queue::assertPushed(DeliverConversion::class, 1);
});

it('no envía eventos importados, ni desactivados en el destino, ni de destinos pausados', function () {
    Queue::fake();
    $this->destination($this->ws, Platform::Meta, ['events_enabled' => ['product.viewed']]);
    $this->destination($this->ws, Platform::TikTok, ['status' => 'paused']);
    $this->postSigned($this->site, $this->secret, [
        measurementLiveOrderPaid($this),
        array_replace(measurementLiveOrderPaid($this), ['event_id' => (string) Str::uuid7(), 'source' => ['type' => 'import', 'platform' => 'woocommerce', 'site_id' => $this->site->publicId(), 'sdk' => 'connector/0.1.0']]),
    ]);
    $this->runWorker();
    Queue::assertNotPushed(DeliverConversion::class);
});

it('envía eventos del navegador con el mismo event_id que el píxel (deduplicación)', function () {
    Queue::fake();
    $this->destination($this->ws, Platform::Meta);
    $view = $this->envelope($this->site, 'product.viewed', ['product_id' => '88', 'name' => 'Pintura', 'price' => '150000.00', 'currency' => 'COP'], [
        'source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'],
        'identity' => ['anonymous_id' => 'anon-9', 'session_id' => 's9'],
        'context' => ['page' => ['url' => 'https://tienda.co/producto/pintura/'], 'consent' => ['analytics' => true, 'marketing' => true]],
    ]);
    $body = json_encode(['k' => $this->site->public_key, 'events' => [$view]]);
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Android)', 'REMOTE_ADDR' => '181.50.1.2'], $body)->assertStatus(202);
    $this->runWorker();

    Queue::assertPushed(DeliverConversion::class, fn (DeliverConversion $j) => $j->payload['event_name'] === 'ViewContent'
        && $j->payload['event_id'] === $view['event_id']
        && $j->payload['user_data']['client_ip_address'] === '181.50.1.2'
        && $j->payload['user_data']['client_user_agent'] === 'Mozilla/5.0 (Android)');
});

it('no encola dos veces el mismo evento aunque la ingesta lo publique de nuevo', function () {
    Queue::fake();
    $this->destination($this->ws, Platform::Meta);
    $router = app(ConversionRouter::class);
    $event = $this->paidEvent($this->ws, $this->site->id);
    $router->handle($event);
    $router->handle($event);
    Queue::assertPushed(DeliverConversion::class, 1);
});

it('nunca usa los destinos de otro espacio de trabajo', function () {
    Queue::fake();
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    $this->destination($other, Platform::Meta);
    $this->postSigned($this->site, $this->secret, [measurementLiveOrderPaid($this)]);
    $this->runWorker();
    Queue::assertNotPushed(DeliverConversion::class);
});
