<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Identity\SiteToken;
use App\Domain\Loyalty\Contracts\MeSection;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/LoyaltyFixtures.php';

/*
 * «Mis recompensas» y el programa en la tienda (api-connector.md §8.3): datos
 * de la persona solo con un igrUser.token válido; canje por cupón o saldo;
 * puntos a ganar y página del programa, públicos y en caché.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    loyaltyFakeSite();
    $this->program = loyaltyProgram($this->ws);
    $this->tiers = loyaltyTiers($this->ws, $this->program);
    $this->reward = loyaltyReward($this->ws, $this->program);
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '300', 'coupons' => []])])]);
    $this->runWorker();
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
    loyaltyIn($this->ws, function ($ledger) {
        $ledger->earnPoints($this->contact->id, 700, 'campaign', 'bono');
        $ledger->creditWallet($this->contact->id, '35000', 'manual', 'regalo');
    });
    $this->token = SiteToken::sign($this->secret, ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() + 3600]);
});

function loyaltyPost($test, string $path, array $body): TestResponse
{
    return $test->call('POST', "/p/{$test->site->public_key}/{$path}", [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode($body));
}

it('responde Mis recompensas solo con un token válido', function () {
    $res = $this->getJson("/p/{$this->site->public_key}/me?token={$this->token}", ['Origin' => 'https://tienda.co'])->assertOk();
    expect($res->json('points.balance'))->toBe(700)->and($res->json('points.pending'))->toBe(270)
        ->and($res->json('points.currency_name'))->toBe('Puntos Pintor')
        ->and($res->json('tier.name'))->toBe('Bronce')->and($res->json('tier.next_name'))->toBe('Plata')
        ->and($res->json('tier.progress_pct'))->toBe(56)                       // 282.000 de 500.000
        ->and($res->json('wallet'))->toBe(['balance' => '35000.00', 'currency' => 'COP'])
        ->and($res->json('rewards.0.name'))->toBe('$20.000 de descuento')->and($res->json('rewards.0.affordable'))->toBeTrue()
        ->and($res->json('program.active'))->toBeTrue();
    expect($res->headers->get('Cache-Control'))->toContain('no-store')->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('https://tienda.co');

    $expired = SiteToken::sign($this->secret, ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() - 10]);
    [$other] = $this->connectedSite($this->ws, 'otra.co');
    $foreign = SiteToken::sign($this->secret, ['ref' => 'wc:77', 'site' => $other->publicId(), 'exp' => time() + 3600]);
    foreach (['', 'basura', $expired, $foreign, $this->token.'x'] as $bad) {
        $res = $this->getJson("/p/{$this->site->public_key}/me?token=".urlencode($bad))->assertStatus(401);
        expect($res->json())->not->toHaveKey('points')->not->toHaveKey('wallet');
    }
    $this->getJson('/p/pk_'.str_repeat('0', 32).'/me?token='.$this->token)->assertNotFound();
});

it('una cuenta que aún no está en el hub ve el programa en cero', function () {
    $token = SiteToken::sign($this->secret, ['ref' => 'wc:555', 'site' => $this->site->publicId(), 'exp' => time() + 3600]);
    $res = $this->getJson("/p/{$this->site->public_key}/me?token={$token}")->assertOk();
    expect($res->json('points.balance'))->toBe(0)->and($res->json('wallet.balance'))->toBe('0.00')->and($res->json('rewards.0.affordable'))->toBeFalse();
});

it('otros módulos agregan su sección sin tocar el controlador', function () {
    app()->bind('test.me_section', fn () => new class implements MeSection
    {
        public function key(): string
        {
            return 'referral';
        }

        public function forContact(Site $site, ?Contact $contact): ?array
        {
            return ['code' => 'ANA7K2', 'contact' => $contact?->publicId()];
        }
    });
    app()->tag(['test.me_section'], MeSection::TAG);
    $res = $this->getJson("/p/{$this->site->public_key}/me?token={$this->token}")->assertOk();
    expect($res->json('referral'))->toBe(['code' => 'ANA7K2', 'contact' => $this->contact->publicId()]);
});

it('canjea puntos por un cupón único y emite points.redeemed', function () {
    $res = loyaltyPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => $this->reward->publicId()])->assertOk();
    expect($res->json('coupon.code'))->toStartWith('PUNTOS-')->and($res->json('coupon.description'))->toBe('$20.000 de descuento')
        ->and($res->json('balance'))->toBe(200)->and($res->json('coupon.expires_at'))->not->toBeNull();
    Http::assertSent(fn (Request $r) => ($r->data()['command'] ?? null) === 'coupon.create' && $r->data()['args']['kind'] === 'fixed_cart' && $r->data()['args']['amount'] === '20000.00');
    $rows = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'coupon_code') AS c, JSONExtractInt(toJSONString(properties), 'points') AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'points.redeemed'");
    expect($rows)->toBe([['c' => $res->json('coupon.code'), 'p' => 500]]);

    // Ya no alcanza para otro.
    loyaltyPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => $this->reward->publicId()])->assertStatus(422)->assertJsonPath('error.code', 'not_redeemed');
    loyaltyPost($this, 'me/redeem', ['token' => 'malo', 'reward_id' => $this->reward->publicId()])->assertStatus(401);
});

it('si la tienda no crea el cupón, los puntos vuelven', function () {
    loyaltySiteDown(true);
    loyaltyPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => $this->reward->publicId()])->assertStatus(422);
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->pointsBalance($this->contact->id))->toBe(700));
});

it('una recompensa de saldo acredita la billetera', function () {
    $wallet = loyaltyReward($this->ws, $this->program, ['name' => '$10.000 de saldo', 'kind' => 'wallet', 'points_cost' => 300, 'value' => '10000.00']);
    $res = loyaltyPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => $wallet->publicId()])->assertOk();
    expect($res->json('wallet.balance'))->toBe('45000.00')->and($res->json('balance'))->toBe(400)->and($res->json())->not->toHaveKey('coupon');
});

it('limita los canjes por IP', function () {
    foreach (range(1, 10) as $i) {
        loyaltyPost($this, 'me/redeem', ['token' => 'x', 'reward_id' => 'rw_x']);
    }
    loyaltyPost($this, 'me/redeem', ['token' => 'x', 'reward_id' => 'rw_x'])->assertStatus(429);
});

it('dice cuántos puntos se ganan con un producto o un carrito, en caché pública', function () {
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'catalog.product_upserted', ['product_id' => '88', 'type' => 'simple', 'name' => 'Pintura', 'price' => '150000.00', 'stock_status' => 'instock', 'status' => 'publish', 'category_ids' => ['12'], 'hash' => str_repeat('b', 32)])]);
    $this->runWorker();
    $res = $this->getJson("/p/{$this->site->public_key}/points?cart_total=282000")->assertOk();
    expect($res->json())->toBe(['points' => 282, 'label' => 'Gana 282 Puntos Pintor'])->and($res->headers->get('Cache-Control'))->toContain('max-age=300');

    $this->program->forceFill(['earn_rules' => array_replace($this->program->earn_rules, ['category_multipliers' => [['category_id' => '12', 'multiplier' => 2]]])])->save();
    expect($this->getJson("/p/{$this->site->public_key}/points?product_id=88")->json('points'))->toBe(300);
});

it('sirve la página explicativa del programa', function () {
    $res = $this->getJson("/p/{$this->site->public_key}/program")->assertOk();
    expect($res->json('active'))->toBeTrue()->and($res->json('explainer.title'))->toBe('Programa Puntos Pintor')
        ->and(collect($res->json('explainer.sections'))->pluck('key')->all())->toBe(['earn', 'redeem', 'tiers', 'wallet', 'conditions'])
        ->and($res->json('explainer.sections.0.items.0'))->toBe('Gana 1 Puntos Pintor por cada $ 1.000 en tus compras.')
        ->and($res->json('tiers.1.benefits'))->toContain('Envío gratis');
});
