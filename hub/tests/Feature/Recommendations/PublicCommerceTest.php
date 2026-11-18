<?php

use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\SiteToken;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Recommendations\Models\OnsiteBanner;
use App\Domain\Recommendations\Models\ProductWatch;
use App\Domain\Recommendations\Stats\ProductStatsBuilder;
use App\Domain\Recommendations\Watches\NotifyProductWatches;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Recommendations\Support\CommerceFixtures;

uses(CommerceFixtures::class);

// M16 en la tienda: GET /reco, POST /watch y GET /banner (api-connector.md §8.3).
beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->commerceSetUp();
    $this->key = $this->site->public_key;
    $this->fake = FakeMessageDispatcher::install();
});

function commerceWatch($test, array $body, string $origin = 'https://tienda.co')
{
    return $test->call('POST', "/p/{$test->key}/watch", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => $origin], json_encode($body));
}

function commerceUserToken($test, string $ref = 'wc:77', int $ttl = 600): string
{
    return SiteToken::sign($test->secret, ['ref' => $ref, 'site' => $test->site->publicId(), 'exp' => time() + $ttl]);
}

function commerceLedger(int $points): void
{
    app()->instance(LoyaltyLedger::class, new class($points) implements LoyaltyLedger
    {
        public function __construct(private int $points) {}

        public function isActive(): bool
        {
            return true;
        }

        public function rulePoints(string $reason): int
        {
            return 0;
        }

        public function earnPoints(string $contactId, int $points, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null, bool $pending = false): ?string
        {
            return null;
        }

        public function settle(string $refType, string $refId, bool $confirm): void {}

        public function creditWallet(string $contactId, string $amount, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null): string
        {
            return 'x';
        }

        public function pointsBalance(string $contactId): int
        {
            return $this->points;
        }

        public function walletBalance(string $contactId): string
        {
            return '0.00';
        }
    });
}

it('sirve recomendaciones con caché pública o privada según el tipo, CORS del sitio y sin datos personales', function () {
    $ana = $this->commerceContact(['first_name' => 'Ana', 'email' => 'ana@ejemplo.com']);
    $this->commerceRun(fn () => ContactIdentity::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $ana->id, 'type' => 'anonymous_id', 'value' => 'anon-ana-12345']));
    $this->commerceProduct('1', 'Pintura Azul');
    $this->commerceProduct('2', 'Rodillo');
    $this->commerceOrder($ana->id, ['1' => 3]);
    $this->commerceRun(fn () => app(ProductStatsBuilder::class)->build($this->ws, $this->site));

    $public = $this->call('GET', "/p/{$this->key}/reco?kind=trending&limit=2", [], [], [], ['HTTP_ORIGIN' => 'https://www.tienda.co'])->assertOk();
    expect($public->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60')
        ->and($public->headers->get('Access-Control-Allow-Origin'))->toBe('https://www.tienda.co')
        ->and($public->json('kind'))->toBe('trending')
        ->and($public->json('products.0.id'))->toBe('1');

    $personal = $this->call('GET', "/p/{$this->key}/reco?kind=para_ti&aid=anon-ana-12345", [], [], [], ['HTTP_ORIGIN' => 'https://evil.example'])->assertOk();
    expect($personal->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=60')
        ->and($personal->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($personal->json('products.*.id'))->toBe(['2'])  // ya compró la Pintura
        ->and($personal->getContent())->not->toContain('ana@ejemplo.com')->not->toContain('Ana');

    $this->getJson("/p/{$this->key}/reco?kind=cualquiera")->assertStatus(422);
    $this->getJson('/p/pk_'.str_repeat('a', 32).'/reco?kind=trending')->assertNotFound();

    // Un tipo apagado en el panel responde vacío.
    DB::table('commerce_settings')->insert(['workspace_id' => $this->ws->id, 'recommendations' => json_encode(['kinds' => ['trending' => false]]), 'created_at' => now(), 'updated_at' => now()]);
    expect($this->getJson("/p/{$this->key}/reco?kind=trending&limit=3")->json('products'))->toBe([]);
});

it('limita las recomendaciones por IP', function () {
    config(['igr-commerce.recommendations.rate_per_minute' => 2]);
    $this->getJson("/p/{$this->key}/reco?kind=trending")->assertOk();
    $this->getJson("/p/{$this->key}/reco?kind=trending")->assertOk();
    $this->getJson("/p/{$this->key}/reco?kind=trending")->assertStatus(429);
});

it('suscribe a «avísame» con consentimiento, una sola vez, y avisa si ya hay stock', function () {
    $this->commerceProduct('88', 'Pintura Azul', ['12'], ['stock_status' => 'outofstock', 'price' => '150000']);
    $this->commerceProduct('91', 'Rodillo', ['12']);

    commerceWatch($this, ['product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => false]])
        ->assertStatus(422)->assertJsonPath('errors.consents.0', 'Marca la casilla para que podamos avisarte por ese medio.');
    commerceWatch($this, ['product_id' => '88', 'kind' => 'back_in_stock'])->assertStatus(422);
    commerceWatch($this, ['product_id' => 'no-existe', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true]])->assertStatus(422);

    $ok = commerceWatch($this, ['product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'phone' => '300 123 4567',
        'consents' => ['email' => true, 'whatsapp' => true], 'anonymous_id' => 'anon-ana-12345', 'page_url' => 'https://tienda.co/p/88'])->assertOk();
    expect($ok->json('message'))->toBe('Listo. Te escribiremos por WhatsApp cuando vuelva.')
        ->and($ok->headers->get('Cache-Control'))->toContain('no-store');
    commerceWatch($this, ['product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true]])->assertOk();

    $watches = $this->commerceRun(fn () => ProductWatch::query()->get());
    expect($watches)->toHaveCount(1)
        ->and($watches[0]->channel)->toBe('email') // la segunda vez dejó solo el correo
        ->and($watches[0]->kind)->toBe('back_in_stock')->and($watches[0]->price_at_subscribe)->toBe('150000.00');
    $events = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'watch_id') AS w, anonymous_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'product_watch.created'");
    expect($events)->toHaveCount(1)->and($events[0]['w'])->toBe($watches[0]->publicId())->and($events[0]['anonymous_id'])->toBe('anon-ana-12345');
    expect(DB::table('consent_events')->where('contact_id', $watches[0]->contact_id)->pluck('source')->unique()->all())->toBe(['watch:88']);

    $available = commerceWatch($this, ['product_id' => '91', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true]])->assertOk();
    expect($available->json('available'))->toBeTrue();

    $drop = commerceWatch($this, ['product_id' => '91', 'kind' => 'price_drop', 'email' => 'leo@ejemplo.com', 'consents' => ['email' => true]])->assertOk();
    expect($drop->json('message'))->toBe('Listo. Te avisaremos si baja de precio.');
    expect($this->commerceRun(fn () => ProductWatch::query()->where('kind', 'price_drop')->value('threshold')))->toBe('10000.00');
});

it('limita los «avísame» por IP y por navegador', function () {
    $this->commerceProduct('88', 'Pintura Azul', ['12'], ['stock_status' => 'outofstock']);
    config(['igr-commerce.watches.per_visitor_per_minute' => 1]);
    $body = ['product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true], 'anonymous_id' => 'anon-rapido-1'];
    commerceWatch($this, $body)->assertOk();
    commerceWatch($this, $body)->assertStatus(429);
});

it('avisa una sola vez cuando vuelve el stock, por la cola de mensajes y como marketing', function () {
    $this->commerceProduct('88', 'Pintura Azul', ['12'], ['stock_status' => 'outofstock', 'price' => '150000']);
    commerceWatch($this, ['product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true]])->assertOk();

    // Un cambio que no lo deja disponible no avisa.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'catalog.stock_changed', ['product_id' => '88', 'from_status' => 'instock', 'to_status' => 'outofstock'])]);
    $this->runWorker();
    expect($this->fake->dispatched)->toHaveCount(0);

    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'catalog.stock_changed', ['product_id' => '88', 'from_status' => 'outofstock', 'to_status' => 'instock', 'qty' => 4])]);
    $this->runWorker();
    expect($this->fake->dispatched)->toHaveCount(1);
    /** @var OutboundMessage $message */
    $message = $this->fake->dispatched[0];
    expect($message->kind)->toBe(MessageKind::Marketing)->and($message->channel->value)->toBe('email')
        ->and($message->context['producto'])->toMatchArray(['nombre' => 'Pintura Azul', 'precio' => '150000.00', 'url' => 'https://tienda.co/p/88'])
        ->and($message->idempotencyKey)->toStartWith('watch:')
        ->and(DB::table('templates')->where('id', substr((string) $message->templateId, 4))->value('library_key'))->toBe('back_in_stock');

    $watch = $this->commerceRun(fn () => ProductWatch::query()->firstOrFail());
    expect($watch->status)->toBe('notified')->and($watch->message_id)->not->toBeNull()->and($watch->notified_price)->toBe('150000.00');
    $events = $this->chRows($this->ws, "SELECT contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'product_watch.notified'");
    expect($events)->toHaveCount(1)->and($events[0]['contact_id'])->toBe($watch->contact_id);

    // Otro aviso de stock o el barrido no vuelven a enviar.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'catalog.stock_changed', ['product_id' => '88', 'from_status' => 'outofstock', 'to_status' => 'instock'])]);
    $this->runWorker();
    $this->artisan('igr:watches:notify', ['--sync' => true])->assertSuccessful();
    expect($this->fake->dispatched)->toHaveCount(1);
});

it('avisa si baja del precio al suscribirse, no si sube, y respeta el tope por persona y producto', function () {
    $this->commerceProduct('88', 'Pintura Azul', ['12'], ['price' => '150000', 'stock_status' => 'outofstock']);
    $body = ['product_id' => '88', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true]];
    commerceWatch($this, $body + ['kind' => 'price_drop'])->assertOk();
    commerceWatch($this, $body + ['kind' => 'back_in_stock'])->assertOk();

    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'catalog.price_changed', ['product_id' => '88', 'from' => '150000.00', 'to' => '160000.00', 'currency' => 'COP'])]);
    $this->runWorker();
    expect($this->fake->dispatched)->toHaveCount(0);

    // Baja de precio y vuelve el stock a la vez: un solo mensaje para la misma persona y producto.
    DB::table('products')->where('external_id', '88')->update(['stock_status' => 'instock']);
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'catalog.price_changed', ['product_id' => '88', 'from' => '160000.00', 'to' => '129000.00', 'currency' => 'COP'])]);
    $this->runWorker();
    expect($this->fake->dispatched)->toHaveCount(1)
        ->and($this->fake->dispatched[0]->context['producto'])->toMatchArray(['precio' => '129000.00', 'precio_anterior' => '150000.00']);
    $statuses = $this->commerceRun(fn () => ProductWatch::query()->pluck('status', 'kind')->all());
    expect($statuses)->toEqual(['price_drop' => 'notified', 'back_in_stock' => 'active']);
    $waiting = $this->commerceRun(fn () => ProductWatch::query()->where('kind', 'back_in_stock')->firstOrFail());
    expect($waiting->last_reason)->toBe('frequency_cap')->and($waiting->next_attempt_at?->isAfter(now()->addDays(6)))->toBeTrue();
});

it('si la política aplaza el aviso lo reintenta a la hora que dice, y lo cancela si la persona no se puede contactar', function () {
    $this->commerceProduct('88', 'Pintura Azul', ['12'], ['stock_status' => 'outofstock']);
    commerceWatch($this, ['product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'ana@ejemplo.com', 'consents' => ['email' => true]])->assertOk();
    DB::table('products')->where('external_id', '88')->update(['stock_status' => 'instock']);
    $until = now()->addHours(10)->startOfHour()->toImmutable();
    $this->fake->respondWith(fn () => DispatchResult::deferred($until, 'sunday'));

    $notify = fn () => $this->commerceRun(fn () => app(NotifyProductWatches::class)->forProduct($this->ws->fresh(), $this->site, '88'));
    expect($notify()['deferred'])->toBe(1);
    $watch = $this->commerceRun(fn () => ProductWatch::query()->firstOrFail());
    expect($watch->status)->toBe('active')->and($watch->next_attempt_at?->equalTo($until))->toBeTrue()->and($watch->last_reason)->toBe('sunday');
    expect($notify())->toBe(['notified' => 0, 'deferred' => 0, 'waiting' => 0, 'cancelled' => 0]); // todavía no es la hora

    $this->travelTo($until->addMinute());
    $this->fake->respondWith(fn () => DispatchResult::denied('no_consent'));
    expect($notify()['cancelled'])->toBe(1);
    expect($this->commerceRun(fn () => ProductWatch::query()->firstOrFail()->status))->toBe('cancelled');
});

it('muestra el banner del segmento: con sesión, con nombre y puntos; sin sesión, el texto sin datos personales', function () {
    commerceLedger(1250);
    $ana = $this->commerceContact(['first_name' => 'Ana', 'rfm_segment' => 'at_risk']);
    $this->commerceRun(function () use ($ana) {
        ContactIdentity::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $ana->id, 'type' => 'customer_ref', 'value' => $this->site->id.':wc:77']);
        ContactIdentity::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $ana->id, 'type' => 'anonymous_id', 'value' => 'anon-ana-12345']);
        OnsiteBanner::query()->create(['name' => 'VIP', 'audience' => 'points', 'min_points' => 500, 'priority' => 10,
            'text' => 'Hola, {{nombre}}: tienes {{puntos}} puntos', 'text_public' => 'Tienes puntos por usar. Inicia sesión para verlos', 'cta' => 'Ver mis puntos', 'url' => 'https://tienda.co/mi-cuenta', 'style' => 'success']);
        OnsiteBanner::query()->create(['name' => 'Te extrañamos', 'audience' => 'at_risk', 'priority' => 20, 'text' => 'Te extrañamos, {{nombre}}', 'text_public' => 'Te extrañamos: 10% con VUELVE10']);
    });

    $withToken = $this->call('GET', "/p/{$this->key}/banner?placement=top&token=".commerceUserToken($this).'&aid=anon-ana-12345', [], [], [], ['HTTP_ORIGIN' => 'https://tienda.co'])->assertOk();
    expect($withToken->json('banner'))->toMatchArray(['text' => 'Hola, Ana: tienes 1.250 puntos', 'cta' => 'Ver mis puntos', 'url' => 'https://tienda.co/mi-cuenta', 'style' => 'success'])
        ->and($withToken->headers->get('Cache-Control'))->toContain('no-store');

    $anonymous = $this->getJson("/p/{$this->key}/banner?placement=top&aid=anon-ana-12345")->assertOk();
    expect($anonymous->json('banner.text'))->toBe('Tienes puntos por usar. Inicia sesión para verlos')
        ->and($anonymous->getContent())->not->toContain('Ana')->not->toContain('1.250');

    // Un token falso o vencido cuenta como sin sesión.
    $fake = SiteToken::sign('sk_falso', ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() + 60]);
    expect($this->getJson("/p/{$this->key}/banner?token={$fake}&aid=anon-ana-12345")->json('banner.text'))->not->toContain('Ana');
    expect($this->getJson("/p/{$this->key}/banner?token=".commerceUserToken($this, 'wc:77', -10))->json('banner'))->toBeNull();

    // Con pocos puntos pasa al siguiente banner (en riesgo).
    commerceLedger(100);
    expect($this->getJson("/p/{$this->key}/banner?token=".commerceUserToken($this))->json('banner.text'))->toBe('Te extrañamos, Ana');
    expect($this->getJson("/p/{$this->key}/banner?aid=anon-desconocido-1")->json('banner'))->toBeNull();
});

it('muestra el banner de carrito pendiente solo al mismo navegador, con el enlace para restaurarlo', function () {
    $this->commerceProduct('88', 'Pintura Azul');
    DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => 'tok-cart-1',
        'anonymous_id' => 'anon-cart-12345', 'items' => json_encode([['product_id' => '88', 'qty' => 2, 'price' => '10000.00']]), 'items_count' => 2,
        'subtotal' => '20000', 'currency' => 'COP', 'status' => 'abandoned', 'last_activity_at' => now()->subHours(3), 'created_at' => now(), 'updated_at' => now()]);
    $this->commerceRun(fn () => OnsiteBanner::query()->create(['name' => 'Carrito', 'audience' => 'pending_cart', 'text' => 'Tu carrito te espera ({{carrito_items}} productos)',
        'text_public' => 'Tu carrito te espera ({{carrito_items}} productos)', 'cta' => 'Terminar mi compra']));

    $banner = $this->getJson("/p/{$this->key}/banner?aid=anon-cart-12345")->assertOk()->json('banner');
    expect($banner['text'])->toBe('Tu carrito te espera (2 productos)')->and($banner['url'])->toContain('igr_cart=');
    expect($this->getJson("/p/{$this->key}/banner?aid=anon-otro-12345")->json('banner'))->toBeNull();
    expect($this->getJson("/p/{$this->key}/banner?placement=account&aid=anon-cart-12345")->json('banner'))->toBeNull();
});
