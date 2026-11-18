<?php

use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Contacts\Models\Contact;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/LoyaltyFixtures.php';

/*
 * «Usar saldo» en el checkout (api-connector.md §5.5): POST /v1/s/wallet/redeem
 * firmado, cupón de un solo uso por min(saldo, total), débito pendiente hasta el
 * order.paid que lo usa, liberado si vence sin usarse o el pedido se cancela.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    loyaltyFakeSite();
    // Ana compró antes: su cuenta de la tienda (wc:77) ya está en el hub.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '100', 'coupons' => []])])]);
    $this->runWorker();
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
    loyaltyIn($this->ws, fn ($ledger) => $ledger->creditWallet($this->contact->id, '35000', 'manual', 'gift'));
});

function loyaltyRedeemWallet($test, array $body = []): TestResponse
{
    return $test->signedJson('POST', '/v1/s/wallet/redeem', $test->site, $test->secret, $body + ['customer_ref' => 'wc:77', 'cart_total' => '282000.00', 'currency' => 'COP', 'cart_token' => 'c_8f2a9d']);
}

function loyaltyCouponCommands(): array
{
    return array_values(array_filter(Http::recorded()->map(fn ($pair) => $pair[0]->data())->all(), fn ($d) => in_array($d['command'] ?? null, ['coupon.create', 'coupon.disable'], true)));
}

it('emite un cupón de un solo uso por el saldo y lo deja como débito pendiente', function () {
    $start = microtime(true);
    $res = loyaltyRedeemWallet($this)->assertOk();
    $ms = (microtime(true) - $start) * 1000;
    $code = $res->json('coupon.code');
    expect($code)->toStartWith('SALDO-')->and($res->json('coupon.amount'))->toBe('35000.00')->and($res->json('balance'))->toBe('35000.00')
        ->and($res->json('coupon.expires_at'))->not->toBeNull()
        ->and($ms)->toBeLessThan(1000);
    Http::assertSent(fn (Request $r) => ($r->data()['command'] ?? null) === 'coupon.create' && $r->data()['args']['kind'] === 'fixed_cart'
        && $r->data()['args']['amount'] === '35000.00' && $r->data()['args']['usage_limit'] === 1 && $r->data()['args']['email_restrictions'] === ['ana@ejemplo.com']);
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('0.00'));

    // Otro clic con el mismo carrito: el mismo cupón, sin crear otro.
    expect(loyaltyRedeemWallet($this)->assertOk()->json('coupon.code'))->toBe($code)
        ->and(count(loyaltyCouponCommands()))->toBe(1);
    // Otro carrito: ya no queda saldo libre.
    loyaltyRedeemWallet($this, ['cart_token' => 'c_otro'])->assertStatus(409)->assertJsonPath('error.code', 'no_balance');
});

it('usa el total del carrito si es menor que el saldo', function () {
    loyaltyRedeemWallet($this, ['cart_total' => '20000.00'])->assertOk()->assertJsonPath('coupon.amount', '20000.00');
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('15000.00'));
});

it('rechaza sin firma, con datos inválidos o sin saldo', function () {
    $this->call('POST', '/v1/s/wallet/redeem', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['customer_ref' => 'wc:77', 'cart_total' => '1000', 'currency' => 'COP']))->assertStatus(401);
    loyaltyRedeemWallet($this, ['cart_total' => 'mucho'])->assertStatus(422);
    loyaltyRedeemWallet($this, ['customer_ref' => 'wc:999'])->assertStatus(409)->assertJsonPath('error.code', 'no_balance');
});

it('el débito se confirma cuando el pedido pagado usa el cupón', function () {
    $code = loyaltyRedeemWallet($this)->json('coupon.code');
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '101', 'coupons' => [strtolower($code)]])])]);
    $this->runWorker();
    loyaltyIn($this->ws, function ($ledger) {
        expect($ledger->walletBalance($this->contact->id))->toBe('0.00');
    });
    // Aunque pase el día, el saldo usado no vuelve.
    $this->travelTo(now()->addHours(30));
    $this->artisan('igr:loyalty:settle')->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('0.00'));
    $rows = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'amount') AS a FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'wallet.debited'");
    expect(array_column($rows, 'a'))->toBe(['35000.00']);

    // Si el pedido se cancela después, el saldo vuelve.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.cancelled', ['order_id' => '101'])]);
    $this->runWorker();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('35000.00'));
    $this->travelBack();
});

it('si el cupón vence sin usarse, se desactiva en la tienda y el saldo vuelve', function () {
    $code = loyaltyRedeemWallet($this)->json('coupon.code');
    $this->artisan('igr:loyalty:settle', ['--now' => now()->addHours(20)->toIso8601String()])->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('0.00'));

    $this->travelTo(now()->addHours(25));
    $this->artisan('igr:loyalty:settle')->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('35000.00'));
    expect(collect(loyaltyCouponCommands())->firstWhere('command', 'coupon.disable')['args']['code'] ?? null)->toBe($code)
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => CouponIssued::query()->where('code', $code)->value('status')))->toBe('expired');
    $this->travelBack();
});

it('no libera el saldo de un cupón que usa un pedido aún sin pagar', function () {
    $code = loyaltyRedeemWallet($this)->json('coupon.code');
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.created', ['order' => $this->orderSnapshot(['order_id' => '102', 'status' => 'on-hold', 'paid_at' => null, 'coupons' => [strtolower($code)]])])]);
    $this->runWorker();
    $this->travelTo(now()->addHours(30));
    $this->artisan('igr:loyalty:settle')->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('0.00'));
    $this->travelBack();
});

it('si la tienda no puede crear el cupón, el saldo queda intacto', function () {
    loyaltySiteDown(true);
    loyaltyRedeemWallet($this)->assertStatus(502)->assertJsonPath('error.code', 'coupon_failed');
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($this->contact->id))->toBe('35000.00'));
});
