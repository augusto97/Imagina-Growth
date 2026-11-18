<?php

use App\Domain\Commerce\Actions\DetectAbandonedCarts;
use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Carritos (hub/CLAUDE.md §5): proyección, abandono con cart.abandoned,
 * recuperación al llegar el pedido, cupones del hub y enlace de restauración.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $this->anon = ['identity' => ['anonymous_id' => 'anon-carrito']];
    // Ana ya es cliente: su navegador quedó vinculado con un pedido anterior.
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'catalog.product_upserted', ['product_id' => '88', 'type' => 'simple', 'name' => 'Pintura Azul 1 galón', 'slug' => 'pintura-azul', 'permalink' => 'https://tienda.co/p/pintura-azul', 'image_url' => 'https://tienda.co/img/88.jpg', 'price' => '150000.00', 'regular_price' => '150000.00', 'stock_status' => 'instock', 'status' => 'publish', 'category_ids' => [], 'hash' => str_repeat('a', 32)]),
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '1000', 'number' => '1000', 'coupons' => []])], $this->anon),
    ]);
    $this->runWorker();
});

function cartEvent($test, string $event, array $props, string $at, array $identity = []): array
{
    return $test->envelope($test->site, $event, $props, ['schema_version' => 3, 'occurred_at' => CarbonImmutable::parse($at)->toIso8601String()] + ($identity === [] ? $test->anon : ['identity' => $identity]));
}

it('proyecta el carrito, lo marca abandonado a los 45 minutos y emite cart.abandoned con el enlace de restauración', function () {
    $this->postSigned($this->site, $this->secret, [
        cartEvent($this, 'cart.item_added', ['cart_token' => 'c_token_ana', 'product_id' => '88', 'qty' => 2, 'price' => '150000.00', 'cart_total' => '300000.00'], '2026-11-18 14:00:00'),
        cartEvent($this, 'cart.updated', ['cart_token' => 'c_token_ana', 'items' => [['product_id' => '88', 'qty' => 2, 'price' => '150000.00']], 'subtotal' => '300000.00', 'coupons' => [], 'currency' => 'COP'], '2026-11-18 14:00:30'),
        cartEvent($this, 'checkout.started', ['cart_token' => 'c_token_ana', 'subtotal' => '300000.00', 'items_count' => 2], '2026-11-18 14:02:00'),
    ]);
    $this->runWorker();
    $cart = app(CurrentWorkspace::class)->run($this->ws, fn () => Cart::query()->firstOrFail());
    expect($cart->status)->toBe('active')->and($cart->items_count)->toBe(2)->and($cart->subtotal_ws)->toBe('300000.00')
        ->and($cart->checkout_started_at)->not->toBeNull()->and($cart->contact_id)->not->toBeNull();

    $detect = app(DetectAbandonedCarts::class);
    expect($detect->handle(CarbonImmutable::parse('2026-11-18 14:30:00'))['abandoned'])->toBe(0);   // 28 min: todavía no
    expect($detect->handle(CarbonImmutable::parse('2026-11-18 14:48:00'))['abandoned'])->toBe(1);
    expect($detect->handle(CarbonImmutable::parse('2026-11-18 14:49:00'))['abandoned'])->toBe(0);   // una sola vez

    $rows = $this->chRows($this->ws, "SELECT contact_id, anonymous_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'cart.abandoned'");
    expect($rows)->toHaveCount(1);
    $props = json_decode($rows[0]['p'], true);
    expect($rows[0]['contact_id'])->toBe($cart->contact_id)->and($rows[0]['anonymous_id'])->toBe('anon-carrito')
        ->and($props['items'][0]['name'])->toBe('Pintura Azul 1 galón')
        ->and($props['items'][0]['image_url'])->toBe('https://tienda.co/img/88.jpg')
        ->and($props['checkout_started'])->toBeTrue()
        ->and($props['minutes_inactive'])->toBe(46)
        ->and($props['restore_url'])->toStartWith('https://tienda.co/?igr_cart=');

    // El enlace lleva los ítems y lo firma el secreto del sitio (api-connector.md §6.1).
    [$payload, $sig] = explode('.', substr($props['restore_url'], strlen('https://tienda.co/?igr_cart=')));
    expect($sig)->toBe(hash_hmac('sha256', $payload, $this->secret));
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    expect($data['items'])->toBe([['product_id' => '88', 'variation_id' => null, 'qty' => 2]])->and($data['cart'])->toBe($cart->publicId());
});

it('el pedido con cart_token recupera el carrito abandonado y redime el cupón del hub', function () {
    $this->postSigned($this->site, $this->secret, [
        cartEvent($this, 'cart.updated', ['cart_token' => 'c_token_ana', 'items' => [['product_id' => '88', 'qty' => 1, 'price' => '150000.00']], 'subtotal' => '150000.00', 'coupons' => [], 'currency' => 'COP'], '2026-11-18 14:00:00'),
    ]);
    $this->runWorker();
    app(DetectAbandonedCarts::class)->handle(CarbonImmutable::parse('2026-11-18 15:00:00'));
    app(CurrentWorkspace::class)->run($this->ws, fn () => CouponIssued::query()->create([
        'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'code' => 'VUELVE-7K2Q9XAB', 'source_type' => 'flow', 'kind' => 'percent', 'amount' => '10.00', 'status' => 'active',
    ]));

    $this->postSigned($this->site, $this->secret, [
        cartEvent($this, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '1001', 'number' => '1001', 'cart_token' => 'c_token_ana', 'coupons' => ['vuelve-7k2q9xab']])], '2026-11-18 16:00:00'),
    ]);
    $this->runWorker();
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $cart = Cart::query()->firstOrFail();
        $order = DB::table('orders')->where('external_id', '1001')->first();
        expect($cart->status)->toBe('recovered')->and($cart->recovered_order_id)->toBe($order->id);
        $coupon = CouponIssued::query()->firstOrFail();
        expect($coupon->status)->toBe('redeemed')->and($coupon->redeemed_order_id)->toBe($order->id);
    });
});

it('sin abandono el pedido lo convierte; un plugin v2 sin cart_token cierra el carrito del contacto; un anónimo puro nunca se abandona', function () {
    $this->postSigned($this->site, $this->secret, [
        cartEvent($this, 'cart.updated', ['cart_token' => 'c_token_ana', 'items' => [['product_id' => '88', 'qty' => 1]], 'subtotal' => '150000.00', 'coupons' => [], 'currency' => 'COP'], '2026-11-18 14:00:00'),
        cartEvent($this, 'cart.updated', ['cart_token' => 'c_token_nadie', 'items' => [['product_id' => '88', 'qty' => 1]], 'subtotal' => '150000.00', 'coupons' => [], 'currency' => 'COP'], '2026-11-18 14:00:00', ['anonymous_id' => 'anon-desconocido']),
    ]);
    $this->runWorker();
    // Pedido v2 (sin cart_token) del mismo contacto.
    $this->postSigned($this->site, $this->secret, [
        cartEvent($this, 'order.created', ['order' => $this->orderSnapshot(['order_id' => '1002', 'number' => '1002', 'status' => 'pending', 'paid_at' => null])], '2026-11-18 14:10:00'),
    ]);
    $this->runWorker();
    expect(app(DetectAbandonedCarts::class)->handle(CarbonImmutable::parse('2026-11-18 16:00:00'))['abandoned'])->toBe(0);
    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(Cart::query()->where('token', 'c_token_ana')->value('status'))->toBe('converted')
            ->and(Cart::query()->where('token', 'c_token_nadie')->value('status'))->toBe('active');
    });
    // A los 30 días quietos vence.
    expect(app(DetectAbandonedCarts::class)->handle(CarbonImmutable::parse('2026-12-20 10:00:00'))['expired'])->toBe(1);
});

it('un evento viejo no pisa los ítems y un carrito abandonado que vuelve a moverse queda activo', function () {
    $this->postSigned($this->site, $this->secret, [
        cartEvent($this, 'cart.updated', ['cart_token' => 'c_token_ana', 'items' => [['product_id' => '88', 'qty' => 3]], 'subtotal' => '450000.00', 'coupons' => [], 'currency' => 'COP'], '2026-11-18 14:05:00'),
        cartEvent($this, 'cart.updated', ['cart_token' => 'c_token_ana', 'items' => [['product_id' => '88', 'qty' => 1]], 'subtotal' => '150000.00', 'coupons' => [], 'currency' => 'COP'], '2026-11-18 14:00:00'),
    ]);
    $this->runWorker();
    app(DetectAbandonedCarts::class)->handle(CarbonImmutable::parse('2026-11-18 15:00:00'));
    $this->postSigned($this->site, $this->secret, [cartEvent($this, 'cart.item_removed', ['cart_token' => 'c_token_ana', 'product_id' => '99', 'qty' => 1], '2026-11-18 15:05:00')]);
    $this->runWorker();
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $cart = Cart::query()->firstOrFail();
        expect($cart->items_count)->toBe(3)->and($cart->status)->toBe('active')->and($cart->abandoned_at)->not->toBeNull();
    });
});

it('el cupón del hub se crea con el comando firmado; si el sitio falla queda registrado como fallido', function () {
    Http::fake([
        'tienda.co/wp-json/imagina-growth/v1/commands' => Http::sequence()
            ->push(['ok' => true, 'data' => ['coupon_id' => '991', 'code' => 'x']])
            ->push(['ok' => false, 'error' => ['code' => 'coupon_exists', 'message' => 'Ya existe']], 409),
    ]);
    $contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
    $issuer = app(CouponIssuer::class);
    $coupon = app(CurrentWorkspace::class)->run($this->ws, fn () => $issuer->issue(new CouponSpec('percent', '10.00', 'flow', 'fl_1', $contact->id, prefix: 'Vuelve')));
    expect($coupon->status)->toBe('active')->and($coupon->external_coupon_id)->toBe('991')->and($coupon->code)->toMatch('/^VUELVE-[A-HJ-NP-Z2-9]{8}$/');
    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['command'] === 'coupon.create' && $body['args']['kind'] === 'percent' && $body['args']['email_restrictions'] === ['ana@ejemplo.com']
            && $body['args']['usage_limit'] === 1 && $request->hasHeader('X-IGR-Signature');
    });
    expect(fn () => app(CurrentWorkspace::class)->run($this->ws, fn () => $issuer->issue(new CouponSpec('fixed_cart', '20000.00', 'campaign'))))
        ->toThrow(CouponIssueException::class);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => CouponIssued::query()->where('status', 'failed')->count()))->toBe(1);
});

it('el _igr de un enlace rastreado vincula el navegador anónimo al contacto del mensaje sin robarle navegadores a otros', function () {
    $contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
    DB::table('tracked_links')->insert(['code' => 'K7Q2Z9XA', 'workspace_id' => $this->ws->id, 'message_id' => strtoupper((string) Str::ulid()), 'contact_id' => $contact->id, 'url' => 'https://tienda.co/']);
    $view = fn (string $anon, string $code) => $this->envelope($this->site, 'page.viewed', ['page_type' => 'home'], [
        'schema_version' => 2, 'source' => ['type' => 'browser'], 'identity' => ['anonymous_id' => $anon, 'link_token' => $code],
        'context' => ['page' => ['url' => 'https://tienda.co/', 'path' => '/']],
    ]);
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $this->site->public_key, 'events' => [
        $view('anon-celular-nuevo', 'K7Q2Z9XA'),
        $view('anon-carrito', 'NOEXISTE1'),
    ]]))->assertStatus(202);
    $this->runWorker();
    app(CurrentWorkspace::class)->run($this->ws, function () use ($contact) {
        expect(ContactIdentity::query()->where('type', 'anonymous_id')->where('value', 'anon-celular-nuevo')->value('contact_id'))->toBe($contact->id);
    });
    $rows = $this->chRows($this->ws, "SELECT contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'page.viewed' AND anonymous_id = 'anon-celular-nuevo'");
    expect($rows[0]['contact_id'])->toBe($contact->id);

    // Un navegador que ya es de otro contacto no cambia de dueño por abrir un enlace reenviado.
    $other = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Luis']));
    DB::table('tracked_links')->insert(['code' => 'LUIS0001', 'workspace_id' => $this->ws->id, 'message_id' => strtoupper((string) Str::ulid()), 'contact_id' => $other->id, 'url' => 'https://tienda.co/']);
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $this->site->public_key, 'events' => [$view('anon-celular-nuevo', 'LUIS0001')]]))->assertStatus(202);
    $this->runWorker();
    app(CurrentWorkspace::class)->run($this->ws, fn () => expect(ContactIdentity::query()->where('value', 'anon-celular-nuevo')->value('contact_id'))->toBe($contact->id));
});

it('el enlace de restauración no se arma sin ítems', function () {
    $cart = app(CurrentWorkspace::class)->run($this->ws, fn () => Cart::query()->create(['workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => 'c_vacio_000', 'items' => [], 'last_activity_at' => now()]));
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => CartRestoreLink::for($cart)))->toBeNull();
});
