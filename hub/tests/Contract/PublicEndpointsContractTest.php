<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Identity\SiteToken;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Domain\Loyalty\Support\ProgramRules;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Recommendations\Models\OnsiteBanner;
use App\Domain\Reviews\Models\Review;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Recommendations\Support\CommerceFixtures;

uses(CommerceFixtures::class);

/*
 * Contrato de los endpoints públicos de la Fase 3 (api-connector.md §5.1, §5.5 y §8.3):
 * la forma exacta que leen el tracker (tracker/widgets/**) y el plugin
 * (connector/src/Onsite/**). Si una prueba de aquí cambia, cambian también el
 * tracker, el hub simulado del plugin (connector/tests/fixtures/mock-hub.php) y la
 * documentación.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->commerceSetUp();
    $this->key = $this->site->public_key;
    $this->api = "/api/w/{$this->ws->publicId()}";
    FakeMessageDispatcher::install();
    // El sitio acepta los comandos (cupones, niveles, reseñas).
    Http::fake(['tienda.co/wp-json/imagina-growth/v1/commands' => Http::response(['ok' => true, 'data' => ['coupon_id' => '991', 'comment_id' => '551', 'ok' => true]])]);

    // Una clienta con cuenta en la tienda (wc:77), puntos y saldo.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '300', 'coupons' => []])])]);
    $this->runWorker();
    $this->contact = $this->commerceRun(fn () => Contact::query()->firstOrFail());
    $this->commerceRun(fn () => ContactIdentity::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'type' => 'anonymous_id', 'value' => 'aid-ana-123456']));
    $this->program = $this->commerceRun(function () {
        $d = ProgramRules::defaults();
        $program = LoyaltyProgram::query()->create(['status' => 'active', 'currency_name' => 'Puntos Pintor', 'earn_rules' => $d['earn_rules'], 'redeem_rules' => $d['redeem_rules'],
            'expiry_policy' => $d['expiry_policy'], 'pending_days' => 15, 'wallet_rules' => $d['wallet_rules']]);
        app(DatabaseLoyaltyLedger::class)->forgetProgram();
        LoyaltyTier::query()->create(['program_id' => $program->id, 'name' => 'Bronce', 'rank' => 1, 'threshold_type' => 'spend_12m', 'threshold' => '0', 'benefits' => ['multiplier' => 1]]);
        LoyaltyTier::query()->create(['program_id' => $program->id, 'name' => 'Plata', 'rank' => 2, 'threshold_type' => 'spend_12m', 'threshold' => '500000', 'benefits' => ['multiplier' => 1.5, 'free_shipping' => true]]);
        $this->couponReward = LoyaltyReward::query()->create(['program_id' => $program->id, 'name' => '$20.000 de descuento', 'kind' => 'coupon_fixed', 'points_cost' => 500, 'value' => '20000.00', 'status' => 'active']);
        $this->walletReward = LoyaltyReward::query()->create(['program_id' => $program->id, 'name' => '$10.000 de saldo', 'kind' => 'wallet', 'points_cost' => 100, 'value' => '10000.00', 'status' => 'active']);

        return $program;
    });
    $this->commerceRun(function () {
        app(LoyaltyLedger::class)->earnPoints($this->contact->id, 900, 'campaign', 'contrato-bono');
        app(LoyaltyLedger::class)->creditWallet($this->contact->id, '35000', 'manual', 'contrato-regalo');
    });
    $this->token = SiteToken::sign($this->secret, ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() + 3600]);

    // Referidos activos (el regalo del amigo y el del referidor en saldo).
    $this->actingAs($this->owner)->putJson("{$this->api}/referrals/program", [
        'status' => 'active',
        'friend_reward' => ['kind' => 'percent', 'amount' => 10, 'expires_days' => 30, 'minimum_amount' => null],
        'referrer_reward' => ['type' => 'wallet', 'amount' => 20000],
        'rules' => ['min_order' => 0, 'monthly_limit' => 5, 'return_days' => 10, 'qualify_on' => 'completed', 'review_suspicious' => true, 'signal_window_days' => 30],
        'share_text' => 'Te regalo {regalo} en {tienda}: {enlace}',
    ])->assertOk();

    // Catálogo con un agotado, pedidos para los más vendidos, reseñas y un banner.
    $this->commerceProduct('88', 'Pintura Tipo 1', ['12'], ['regular_price' => '12000.00', 'sale_price' => '10000.00']);
    $this->commerceProduct('91', 'Rodillo', ['12']);
    $this->commerceProduct('95', 'Brocha', ['12'], ['stock_status' => 'outofstock']);
    $this->commerceOrder($this->contact->id, ['88' => 1, '91' => 2]);
    $this->commerceRun(fn () => Review::query()->create([
        'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'contact_id' => $this->contact->id, 'source' => 'request', 'verified_buyer' => true,
        'external_product_id' => '88', 'rating' => 5, 'title' => 'Excelente', 'body' => 'Cubre con una mano', 'author_name' => 'Ana R.', 'status' => 'published',
        'published_at' => now(), 'owner_reply' => '¡Gracias, Ana!', 'media' => [['url' => 'https://cdn.judge.me/foto.jpg', 'type' => 'image', 'thumb' => 'https://cdn.judge.me/foto_t.jpg']],
    ]));
    $this->commerceRun(fn () => OnsiteBanner::query()->create(['name' => 'VIP', 'placement' => 'account', 'audience' => 'points', 'min_points' => 100, 'priority' => 10,
        'text' => '{{nombre}}, tienes {{puntos}} puntos', 'text_public' => 'Únete al programa de puntos', 'cta' => 'Ver', 'url' => 'https://tienda.co/mi-cuenta/recompensas/', 'style' => 'success']));

    // Un widget activo de cada tipo.
    $this->widgets = [];
    foreach (['popup', 'embedded_form', 'bar', 'spin_wheel', 'teaser', 'two_step', 'quiz', 'wa_widget', 'referral_welcome'] as $kind) {
        $id = $this->actingAs($this->owner)->postJson("{$this->api}/onsite/widgets", ['name' => "Widget {$kind}", 'kind' => $kind])->assertCreated()->json('id');
        if ($kind === 'wa_widget') {
            $this->actingAs($this->owner)->patchJson("{$this->api}/onsite/widgets/{$id}", ['variants' => [['content' => ['title' => 'Escríbenos', 'cta' => 'Abrir', 'wa' => ['number' => '+57 300 123 4567', 'text' => 'Hola']]]]])->assertOk();
        }
        $this->actingAs($this->owner)->postJson("{$this->api}/onsite/widgets/{$id}/activate")->assertOk();
        $this->widgets[$kind] = $id;
    }
});

/** POST público con cuerpo JSON en text/plain, como lo manda el tracker. */
function contractPost($test, string $path, array $body, string $ip = '181.50.1.2'): TestResponse
{
    return $test->call('POST', "/p/{$test->key}/{$path}", [], [], [], [
        'CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://tienda.co', 'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0',
    ], json_encode($body));
}

/** @param array<string, mixed> $row */
function contractKeys(array $row): array
{
    $keys = array_keys($row);
    sort($keys);

    return $keys;
}

const CONTRACT_CARD = ['currency', 'id', 'image', 'name', 'price', 'rating_avg', 'rating_count', 'regular_price', 'sale_price', 'url'];

it('widgets.json: la forma de cada widget, las casillas con texto legal y nada secreto', function () {
    $res = $this->call('GET', "/p/{$this->key}/widgets.json?page_type=home&path=/", [], [], [], ['HTTP_ORIGIN' => 'https://tienda.co'])->assertOk();
    expect($res->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('https://tienda.co')
        ->and(contractKeys($res->json()))->toBe(['consents', 'widgets']);
    foreach ($res->json('consents') as $c) {
        expect(contractKeys($c))->toBe(['channel', 'label', 'version']);
    }
    $byKind = collect($res->json('widgets'))->keyBy('kind');
    expect($byKind->keys()->sort()->values()->all())->toBe(['bar', 'embedded_form', 'popup', 'quiz', 'referral_welcome', 'spin_wheel', 'teaser', 'two_step', 'wa_widget']);

    foreach ($byKind as $kind => $w) {
        expect(array_slice(contractKeys($w), 0))->toContain('content', 'design', 'gives_coupon', 'id', 'kind', 'priority', 'slot', 'targeting', 'variants')
            ->and($w['id'])->toMatch('/^wg_[0-9A-Z]{26}$/')
            ->and(contractKeys($w['design']))->toBe(['accent', 'accent_text', 'bg', 'display', 'image', 'image_layout', 'layout', 'position', 'text'])
            ->and($w['targeting'])->toHaveKeys(['trigger', 'pages', 'page_types', 'devices', 'new_visitors', 'utm', 'referral', 'countries', 'segments', 'cart_value_min', 'hide_for_subscribers', 'frequency'])
            ->and($w['targeting']['trigger'])->toHaveKeys(['type', 'value'])
            ->and($w['targeting']['pages'])->toHaveKeys(['include', 'exclude'])
            ->and($w['content'])->toHaveKeys(['title', 'body', 'fields', 'consents', 'cta', 'success'])
            ->and($w['content'])->not->toHaveKey('wa')->not->toHaveKey('step2');
        foreach ($w['variants'] as $v) {
            expect(contractKeys($v))->toBe(['content', 'design', 'key', 'weight']);
        }
        foreach ($w['content']['fields'] as $f) {
            expect($f)->toHaveKeys(['key', 'type', 'required', 'label']);
        }
        foreach ($w['content']['consents'] as $c) {
            expect(contractKeys($c))->toBe(['channel', 'label', 'version']);
        }
    }
    // Incrustados en un slot; flotantes sin slot.
    expect($byKind['embedded_form']['slot'])->toBe('form')->and($byKind['popup']['slot'])->toBeNull()->and($byKind['quiz']['slot'])->toBeNull()
        ->and($byKind['popup']['design']['layout'])->toBe('center')->and($byKind['bar']['design']['position'])->toBe('top')
        ->and($byKind['quiz']['design']['display'])->toBe('popup')
        ->and($byKind['teaser']['content'])->toHaveKey('teaser')
        ->and($byKind['referral_welcome']['targeting']['referral'])->toBeTrue()
        ->and($byKind['popup']['targeting']['referral'])->toBeFalse();
    // Dos pasos: cada paso con sus casillas y «No, gracias» solo en el segundo.
    $steps = $byKind['two_step']['content']['steps'];
    expect($steps)->toHaveCount(2)
        ->and(array_column($steps[0]['fields'], 'key'))->toBe(['email'])->and(array_column($steps[0]['consents'], 'channel'))->toBe(['email'])
        ->and(array_column($steps[1]['fields'], 'key'))->toBe(['phone'])->and(array_column($steps[1]['consents'], 'channel'))->toBe(['whatsapp'])
        ->and($steps[0])->not->toHaveKey('no')->and($steps[1]['no'])->toBe('No, gracias');
    // Ruleta sin probabilidades, quiz con ramas y WhatsApp con número.
    expect(contractKeys($byKind['spin_wheel']['content']['slices'][0]))->toBe(['key', 'label'])
        ->and($byKind['spin_wheel']['content'])->toHaveKey('lose')
        ->and(contractKeys($byKind['quiz']['quiz']['questions'][0]))->toBe(['id', 'options', 'text'])
        ->and($byKind['quiz']['quiz']['questions'][0]['options'][0])->toHaveKeys(['key', 'label'])
        ->and($byKind['quiz']['content'])->toHaveKeys(['start', 'contact_optional'])
        ->and($byKind['wa_widget']['wa'])->toBe(['number' => '573001234567', 'text' => 'Hola', 'position' => 'right']);
    expect($res->getContent())->not->toContain('weight":50')->not->toContain('coupon_config')->not->toContain('expires_in_days')
        ->not->toContain('ana@ejemplo.com')->not->toContain('outcomes');

    // El checkout nunca trae widgets; la página de gracias suma la encuesta.
    expect($this->getJson("/p/{$this->key}/widgets.json?page_type=checkout")->json('widgets'))->toBe([]);
    $this->getJson('/p/pk_'.str_repeat('0', 32).'/widgets.json')->assertNotFound();
});

it('quiz con el contacto opcional: los campos no son obligatorios', function () {
    $id = $this->widgets['quiz'];
    $this->actingAs($this->owner)->patchJson("{$this->api}/onsite/widgets/{$id}", ['variants' => [['content' => ['title' => 'Tu producto ideal', 'cta' => 'Ver', 'fields' => [['key' => 'email', 'type' => 'email', 'required' => true, 'label' => 'Tu correo']], 'contact_optional' => true]]]])->assertOk();
    $quiz = collect($this->getJson("/p/{$this->key}/widgets.json?page_type=home")->json('widgets'))->firstWhere('kind', 'quiz');
    expect(array_unique(array_column($quiz['content']['fields'], 'required')))->toBe([false]);
});

it('visitor.json: misma forma con o sin anonymous_id, privada y sin datos personales', function () {
    foreach (['', '?aid=aid-ana-123456'] as $q) {
        $res = $this->call('GET', "/p/{$this->key}/visitor.json{$q}", [], [], [], ['HTTP_ORIGIN' => 'https://tienda.co', 'HTTP_CF_IPCOUNTRY' => 'CO'])->assertOk();
        expect(contractKeys($res->json()))->toBe(['country', 'known', 'returning', 'segments', 'subscribed'])
            ->and(contractKeys($res->json('subscribed')))->toBe(['email', 'whatsapp'])
            ->and($res->json('country'))->toBe('CO')
            ->and($res->headers->get('Cache-Control'))->toContain('no-store')
            ->and($res->getContent())->not->toContain('ana@')->not->toContain('Ana');
    }
    expect($this->getJson("/p/{$this->key}/visitor.json")->json('known'))->toBeFalse()
        ->and($this->getJson("/p/{$this->key}/visitor.json?aid=aid-ana-123456")->json('known'))->toBeTrue();
});

it('submit: 200, 409, 422 y 404 con la forma que lee el tracker', function () {
    $popup = $this->widgets['popup'];
    $ok = contractPost($this, "widgets/{$popup}/submit", ['anonymous_id' => 'aid-nuevo-1', 'variant' => 'a', 'page_url' => 'https://tienda.co/', 'step' => 1,
        'fields' => ['phone' => '300 765 4321', 'first_name' => 'Marta'], 'consents' => ['whatsapp' => true]])->assertOk();
    expect(array_diff(array_keys($ok->json()), ['ok', 'message', 'coupon', 'referral_coupon', 'prize', 'products', 'double_opt_in', 'next_step']))->toBe([])
        ->and($ok->json('ok'))->toBeTrue()->and($ok->json('double_opt_in'))->toBeFalse()->and($ok->json('message'))->toBeString()
        ->and(contractKeys($ok->json('coupon')))->toBe(['code', 'description', 'expires_at'])
        ->and($ok->json('coupon.expires_at'))->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($ok->headers->get('Cache-Control'))->toContain('no-store')
        ->and($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://tienda.co');

    $again = contractPost($this, "widgets/{$popup}/submit", ['anonymous_id' => 'aid-nuevo-1', 'variant' => 'a', 'step' => 1, 'fields' => ['phone' => '300 765 4321'], 'consents' => []])->assertStatus(409);
    expect($again->json('ok'))->toBeFalse()->and($again->json('error'))->toBeString()->and($again->json('message'))->toBeString();

    $bad = contractPost($this, "widgets/{$popup}/submit", ['anonymous_id' => 'aid-nuevo-2', 'step' => 1, 'fields' => ['phone' => '12'], 'consents' => []], '181.50.1.3')->assertStatus(422);
    expect($bad->json('ok'))->toBeFalse()->and($bad->json('message'))->toBeString()->and($bad->json('errors'))->toBeArray()->toHaveKey('phone');

    contractPost($this, 'widgets/wg_01JD3QG7Y2V8K6N4M5P9R0S1T2/submit', ['step' => 1, 'fields' => ['phone' => '3001234567']])->assertNotFound()->assertJsonPath('ok', false);

    // Dos pasos: el primero pide el segundo; la ruleta da su premio; el quiz, productos con la forma de las tarjetas.
    $two = contractPost($this, "widgets/{$this->widgets['two_step']}/submit", ['anonymous_id' => 'aid-dos-1', 'step' => 1, 'fields' => ['email' => 'pao@ejemplo.com'], 'consents' => ['email' => true]], '181.50.1.4')->assertOk();
    expect($two->json('next_step'))->toBe(2);
    $wheel = contractPost($this, "widgets/{$this->widgets['spin_wheel']}/submit", ['anonymous_id' => 'aid-rueda-1', 'step' => 1, 'fields' => ['phone' => '3157654321'], 'consents' => ['whatsapp' => true]], '181.50.1.5')->assertOk();
    expect(contractKeys($wheel->json('prize')))->toBe(['key', 'label']);
    $quiz = collect($this->getJson("/p/{$this->key}/widgets.json?page_type=home")->json('widgets'))->firstWhere('kind', 'quiz');
    $answers = [];
    foreach ($quiz['quiz']['questions'] as $q) {
        $answers[$q['id']] = $q['options'][0]['key'];
    }
    $res = contractPost($this, "widgets/{$this->widgets['quiz']}/submit", ['anonymous_id' => 'aid-quiz-1', 'step' => 1, 'fields' => ['email' => 'quiz@ejemplo.com'], 'consents' => [], 'answers' => $answers], '181.50.1.6')->assertOk();
    foreach ($res->json('products') ?? [] as $p) {
        expect(contractKeys($p))->toBe(CONTRACT_CARD);
    }
});

it('reco: tarjetas de producto, fallback y caché según el tipo', function () {
    $public = $this->call('GET', "/p/{$this->key}/reco?kind=best_sellers_category&category_id=12&limit=4", [], [], [], ['HTTP_ORIGIN' => 'https://tienda.co'])->assertOk();
    expect(contractKeys($public->json()))->toBe(['fallback', 'kind', 'products'])
        ->and($public->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60')
        ->and(array_column($public->json('products'), 'id'))->not->toContain('95');
    foreach ($public->json('products') as $p) {
        expect(contractKeys($p))->toBe(CONTRACT_CARD);
    }
    $personal = $this->getJson("/p/{$this->key}/reco?kind=complete_cart&cart=88&aid=aid-ana-123456")->assertOk();
    expect($personal->headers->get('Cache-Control'))->toContain('private')->and(contractKeys($personal->json()))->toBe(['fallback', 'kind', 'products']);
    $this->getJson("/p/{$this->key}/reco?kind=nada")->assertStatus(422);
});

it('watch: 200 con o sin disponibilidad y 422 con message y errors', function () {
    $ok = contractPost($this, 'watch', ['anonymous_id' => 'aid-w-1', 'product_id' => '95', 'kind' => 'back_in_stock', 'email' => 'w@ejemplo.com', 'consents' => ['email' => true], 'consent_version' => 'v1'])->assertOk();
    expect(contractKeys($ok->json()))->toBe(['message', 'ok']);
    $available = contractPost($this, 'watch', ['anonymous_id' => 'aid-w-2', 'product_id' => '88', 'kind' => 'back_in_stock', 'email' => 'w2@ejemplo.com', 'consents' => []], '181.50.1.7')->assertOk();
    expect(contractKeys($available->json()))->toBe(['available', 'message', 'ok']);
    $bad = contractPost($this, 'watch', ['product_id' => '95', 'kind' => 'back_in_stock'], '181.50.1.8')->assertStatus(422);
    expect(contractKeys($bad->json()))->toBe(['error', 'errors', 'message'])->and($bad->json('message'))->toBe('Escribe un correo o un WhatsApp válido.');
});

it('banner: sin token, el texto público; con token, el personal', function () {
    $anon = $this->getJson("/p/{$this->key}/banner?placement=account")->assertOk();
    expect(contractKeys($anon->json()))->toBe(['banner']);
    $mine = $this->getJson("/p/{$this->key}/banner?placement=account&token={$this->token}")->assertOk();
    expect(contractKeys($mine->json('banner')))->toBe(['cta', 'id', 'style', 'text', 'url'])
        ->and($mine->json('banner.text'))->toContain('puntos')
        ->and($mine->headers->get('Cache-Control'))->toContain('no-store');
    expect(json_encode($anon->json()))->not->toContain('Ana');
});

it('me: 401 sin token válido; con token, la forma completa con la sección de referidos', function () {
    foreach (['', 'basura', $this->token.'x'] as $bad) {
        $res = $this->getJson("/p/{$this->key}/me?token=".urlencode($bad))->assertStatus(401);
        expect($res->json())->toBe(['ok' => false, 'error' => ['code' => 'invalid_token']]);
    }
    $me = $this->getJson("/p/{$this->key}/me?token={$this->token}")->assertOk();
    expect(contractKeys($me->json()))->toBe(['points', 'program', 'referral', 'rewards', 'tier', 'wallet'])
        ->and(contractKeys($me->json('program')))->toBe(['active', 'currency_name'])
        ->and(contractKeys($me->json('points')))->toBe(['balance', 'currency_name', 'expiring', 'pending'])
        ->and($me->json('points.balance'))->toBeInt()
        ->and(contractKeys($me->json('tier')))->toBe(['benefits', 'name', 'next_name', 'progress_pct'])
        ->and($me->json('wallet'))->toBe(['balance' => '35000.00', 'currency' => 'COP'])
        ->and(contractKeys($me->json('rewards.0')))->toBe(['affordable', 'id', 'kind', 'name', 'points'])
        ->and(contractKeys($me->json('referral')))->toBe(['code', 'friend_reward', 'link', 'referrer_reward', 'share_text'])
        ->and($me->json('referral.link'))->toStartWith('https://tienda.co/?ref=')
        ->and($me->json('referral.share_text'))->toContain($me->json('referral.link'))
        ->and($me->headers->get('Cache-Control'))->toContain('no-store');
});

it('me/redeem: cupón o saldo, 401 y 422 con error.code y error.message', function () {
    $coupon = contractPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => $this->couponReward->publicId()])->assertOk();
    expect(contractKeys($coupon->json()))->toBe(['balance', 'coupon', 'ok'])
        ->and(contractKeys($coupon->json('coupon')))->toBe(['code', 'description', 'expires_at'])
        ->and($coupon->json('coupon.expires_at'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    $wallet = contractPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => $this->walletReward->publicId()], '181.50.1.9')->assertOk();
    expect(contractKeys($wallet->json()))->toBe(['balance', 'ok', 'wallet'])->and(contractKeys($wallet->json('wallet')))->toBe(['balance', 'currency']);
    contractPost($this, 'me/redeem', ['token' => 'x', 'reward_id' => $this->couponReward->publicId()], '181.50.1.10')->assertStatus(401)->assertExactJson(['ok' => false, 'error' => ['code' => 'invalid_token']]);
    $bad = contractPost($this, 'me/redeem', ['token' => $this->token, 'reward_id' => 'rw_01JD3QG7Y2V8K6N4M5P9R0S1T2'], '181.50.1.11')->assertStatus(422);
    expect($bad->json('ok'))->toBeFalse()->and(contractKeys($bad->json('error')))->toBe(['code', 'message']);
});

it('me/share: 202 con token y 401 sin él', function () {
    contractPost($this, 'me/share', ['token' => $this->token, 'channel' => 'whatsapp'])->assertStatus(202)->assertExactJson(['ok' => true]);
    contractPost($this, 'me/share', ['token' => 'x', 'channel' => 'whatsapp'], '181.50.1.12')->assertStatus(401)->assertJsonPath('error', 'invalid_token');
});

it('points y program: públicos, en caché y con la forma documentada', function () {
    $points = $this->getJson("/p/{$this->key}/points?product_id=88&price=100000")->assertOk();
    expect(contractKeys($points->json()))->toBe(['label', 'points'])->and($points->json('points'))->toBeInt()
        ->and($points->headers->get('Cache-Control'))->toContain('public');
    $program = $this->getJson("/p/{$this->key}/program")->assertOk();
    expect(contractKeys($program->json()))->toBe(['active', 'currency_name', 'explainer', 'rewards', 'tiers'])
        ->and(contractKeys($program->json('tiers.0')))->toBe(['benefits', 'name'])
        ->and(contractKeys($program->json('rewards.0')))->toBe(['id', 'kind', 'name', 'points']);
});

it('reviews y badges: forma de la lista, medios e insignias', function () {
    $res = $this->getJson("/p/{$this->key}/reviews?product_id=88&page=1&per_page=10")->assertOk();
    expect(contractKeys($res->json()))->toBe(['next_page', 'reviews', 'summary'])
        ->and(contractKeys($res->json('summary')))->toBe(['avg', 'count', 'distribution'])
        ->and(contractKeys($res->json('reviews.0')))->toBe(['author', 'body', 'created_at', 'id', 'media', 'product_id', 'rating', 'reply', 'title', 'verified'])
        ->and(contractKeys($res->json('reviews.0.media.0')))->toBe(['thumb', 'type', 'url'])
        ->and($res->json('reviews.0.reply'))->toBeString();
    expect($this->getJson("/p/{$this->key}/reviews/badges?product_ids=88,91")->json())->toBe(['88' => ['avg' => 5, 'count' => 1]]);
});

it('/v1/s/config dice qué módulos pinta el plugin', function () {
    $config = $this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->assertOk();
    expect($config->json('onsite'))->toBe(['loyalty' => true, 'wallet' => true, 'product_watch' => true]);
    $this->commerceRun(fn () => LoyaltyProgram::query()->update(['status' => 'paused']));
    expect($this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->json('onsite'))->toBe(['loyalty' => false, 'wallet' => false, 'product_watch' => true]);
});

it('/v1/s/wallet/redeem: cupón con el token de carrito del plugin (hex) y 409 con error.code', function () {
    $body = ['customer_ref' => 'wc:77', 'cart_total' => '20000.00', 'currency' => 'COP', 'cart_token' => 'a3f9c2e1b7d84f0e9c1a2b3c4d5e6f70'];
    $ok = $this->signedJson('POST', '/v1/s/wallet/redeem', $this->site, $this->secret, $body)->assertOk();
    expect(contractKeys($ok->json()))->toBe(['balance', 'coupon'])->and(contractKeys($ok->json('coupon')))->toBe(['amount', 'code', 'expires_at'])
        ->and($ok->json('coupon.amount'))->toBe('20000.00');
    // El mismo carrito pide otra vez: el mismo cupón.
    expect($this->signedJson('POST', '/v1/s/wallet/redeem', $this->site, $this->secret, $body)->json('coupon.code'))->toBe($ok->json('coupon.code'));
    $none = $this->signedJson('POST', '/v1/s/wallet/redeem', $this->site, $this->secret, ['customer_ref' => 'wc:999'] + $body)->assertStatus(409);
    expect($none->json())->toBe(['ok' => false, 'error' => ['code' => 'no_balance']]);
});
