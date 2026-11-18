<?php

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Onsite\Models\WidgetCapture;
use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Segments\Models\Segment;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// Endpoints públicos de captación (api-connector.md §8.3): widgets.json, visitor.json, envío y doble opt-in.
beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->key = $this->site->public_key;
    $this->base = "/api/w/{$this->ws->publicId()}/onsite";
    $this->messages = FakeMessageDispatcher::install();
    $this->issued = new ArrayObject;
    $this->couponFails = false;
    $test = $this;
    app()->instance(CouponIssuer::class, new class($test) implements CouponIssuer
    {
        public function __construct(private $test) {}

        public function issue(CouponSpec $spec): CouponIssued
        {
            if ($this->test->couponFails) {
                throw new CouponIssueException('El sitio no respondió');
            }
            $this->test->issued[] = $spec;

            return CouponIssued::query()->create([
                'workspace_id' => $this->test->ws->id, 'site_id' => $spec->siteId, 'code' => $spec->prefix.'-PRUEBA'.count($this->test->issued),
                'contact_id' => $spec->contactId, 'source_type' => $spec->sourceType, 'source_id' => $spec->sourceId,
                'kind' => $spec->kind, 'amount' => $spec->amount, 'rules' => array_filter(['minimum_amount' => $spec->minimumAmount]),
                'status' => 'active', 'expires_at' => now()->addDays($spec->expiresInDays),
            ]);
        }
    });
    $this->referralCalls = new ArrayObject;
    app()->instance(ReferralRewards::class, new class($test) implements ReferralRewards
    {
        public function __construct(private $test) {}

        public function onFriendCaptured(string $contactId, string $code, ?string $anonymousId, bool $wasKnown, ?string $siteId = null, ?string $ip = null): ?CouponIssued
        {
            $this->test->referralCalls[] = compact('contactId', 'code', 'anonymousId', 'wasKnown', 'siteId', 'ip');

            return $code === 'ANA7K2' ? CouponIssued::query()->create([
                'workspace_id' => $this->test->ws->id, 'site_id' => $this->test->site->id, 'code' => 'AMIGO-X'.count($this->test->referralCalls), 'contact_id' => $contactId,
                'source_type' => 'referral', 'kind' => 'percent', 'amount' => '10.00', 'status' => 'active', 'expires_at' => now()->addDays(30),
            ]) : null;
        }
    });
});

/** Crea y activa un widget por la API con los cambios dados. */
function onsiteWidget($test, string $kind, array $patch = []): string
{
    $id = $test->actingAs($test->owner)->postJson("{$test->base}/widgets", ['name' => "Widget {$kind}", 'kind' => $kind])->assertCreated()->json('id');
    if ($patch !== []) {
        $test->actingAs($test->owner)->patchJson("{$test->base}/widgets/{$id}", $patch)->assertOk();
    }
    $test->actingAs($test->owner)->postJson("{$test->base}/widgets/{$id}/activate")->assertOk();

    return $id;
}

function onsiteSubmit($test, string $widgetId, array $body, array $server = []): TestResponse
{
    return $test->call('POST', "/p/{$test->key}/widgets/{$widgetId}/submit", [], [], [],
        $server + ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://tienda.co', 'REMOTE_ADDR' => '181.50.1.2', 'HTTP_USER_AGENT' => 'Mozilla/5.0'],
        json_encode($body));
}

function onsiteInWs($test, callable $fn)
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

it('widgets.json publica los widgets activos con la encuesta, sin probabilidades ni cupones, cacheable y con CORS', function () {
    $popup = onsiteWidget($this, 'popup', ['targeting' => ['page_types' => ['home', 'product'], 'pages' => ['exclude' => ['/carrito*']]]]);
    $wheel = onsiteWidget($this, 'spin_wheel');
    $wa = onsiteWidget($this, 'wa_widget', ['variants' => [['content' => ['title' => 'Escríbenos', 'cta' => 'Abrir', 'wa' => ['number' => '+57 300 123 4567', 'text' => 'Hola']]]]]);
    $quiz = onsiteWidget($this, 'quiz');
    $draft = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'Borrador', 'kind' => 'bar'])->json('id');
    $future = onsiteWidget($this, 'bar', ['starts_at' => now()->addDay()->toIso8601String()]);
    $otherSite = Site::withoutWorkspaceScope()->find($this->connectedSite($this->ws, 'otra.co')[0]->id);
    onsiteWidget($this, 'teaser', ['site_id' => $otherSite->publicId()]);

    $res = $this->call('GET', "/p/{$this->key}/widgets.json?page_type=product&path=/producto/pintura", [], [], [], ['HTTP_ORIGIN' => 'https://www.tienda.co'])->assertOk();
    expect($res->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('https://www.tienda.co');
    $ids = array_column($res->json('widgets'), 'id');
    expect($ids)->toContain($popup, $wheel, $wa, $quiz)->not->toContain($draft, $future)->toHaveCount(4);

    $json = $res->getContent();
    expect($json)->not->toContain('weight":50')->not->toContain('coupon_config')->not->toContain('RULETA')->not->toContain('expires_in_days');
    $w = collect($res->json('widgets'))->keyBy('id');
    expect($w[$popup])->toHaveKeys(['id', 'kind', 'slot', 'variants', 'targeting', 'content', 'design', 'gives_coupon'])
        ->and($w[$popup]['gives_coupon'])->toBeTrue()
        ->and($w[$popup]['variants'][0])->toHaveKeys(['key', 'weight', 'design', 'content'])
        ->and($w[$popup]['content']['consents'][0])->toBe(['channel' => 'whatsapp', 'label' => $w[$popup]['content']['consents'][0]['label'], 'version' => 'v1'])
        ->and($w[$popup]['targeting']['utm'])->toBe([])
        ->and($w[$wheel]['content']['slices'])->toBe([['key' => 'p5', 'label' => '5%'], ['key' => 'p10', 'label' => '10%'], ['key' => 'envio', 'label' => 'Envío gratis'], ['key' => 'p20', 'label' => '20%']])
        ->and($w[$wa]['wa'])->toBe(['number' => '573001234567', 'text' => 'Hola', 'position' => 'right'])
        ->and($w[$quiz]['quiz']['questions'][0]['options'][0])->toBe(['key' => 'casa', 'label' => 'Para mi casa', 'next' => 'q2'])
        ->and($json)->not->toContain('quiz_uso');   // los atributos del perfil no salen al navegador

    // Filtra por tipo de página y ruta; nunca en el checkout; en gracias, también la encuesta.
    $cart = $this->getJson("/p/{$this->key}/widgets.json?page_type=home&path=/carrito/")->json('widgets');
    expect(array_column($cart, 'id'))->not->toContain($popup);
    expect($this->getJson("/p/{$this->key}/widgets.json?page_type=checkout")->json('widgets'))->toBe([]);
    $thanks = array_column($this->getJson("/p/{$this->key}/widgets.json?page_type=thankyou")->json('widgets'), 'kind');
    expect($thanks)->toContain('hdyhau_survey', 'spin_wheel')->not->toContain('popup');
});

it('visitor.json responde privado y sin datos personales: conocido, suscrito, país y segmentos en uso', function () {
    $segment = onsiteInWs($this, fn () => Segment::query()->create(['name' => 'VIP', 'kind' => 'static']));
    $unused = onsiteInWs($this, fn () => Segment::query()->create(['name' => 'Otro', 'kind' => 'static']));
    onsiteWidget($this, 'popup', ['targeting' => ['segments' => [$segment->publicId()], 'countries' => ['CO']]]);

    $anon = $this->call('GET', "/p/{$this->key}/visitor.json?aid=nuevo-1", [], [], [], ['HTTP_ORIGIN' => 'https://tienda.co', 'HTTP_CF_IPCOUNTRY' => 'mx'])->assertOk();
    expect($anon->json())->toBe(['known' => false, 'returning' => false, 'subscribed' => ['email' => false, 'whatsapp' => false], 'country' => 'MX', 'segments' => []])
        ->and($anon->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $w = onsiteWidget($this, 'embedded_form');
    onsiteSubmit($this, $w, ['anonymous_id' => 'anon-ana', 'fields' => ['email' => 'ana@ejemplo.com', 'first_name' => 'Ana'], 'consents' => ['email' => true]])->assertOk();
    $contact = onsiteInWs($this, fn () => Contact::query()->firstOrFail());
    DB::table('segment_memberships')->insert([
        ['segment_id' => $segment->id, 'contact_id' => $contact->id, 'workspace_id' => $this->ws->id, 'entered_at' => now()],
        ['segment_id' => $unused->id, 'contact_id' => $contact->id, 'workspace_id' => $this->ws->id, 'entered_at' => now()],
    ]);

    $known = $this->getJson("/p/{$this->key}/visitor.json?aid=anon-ana")->assertOk();
    expect($known->json())->toBe(['known' => true, 'returning' => false, 'subscribed' => ['email' => true, 'whatsapp' => false], 'country' => null, 'segments' => [$segment->publicId()]])
        ->and($known->getContent())->not->toContain('ana@')->not->toContain('Ana');
    $this->getJson('/p/pk_'.str_repeat('a', 32).'/visitor.json?aid=x')->assertNotFound();
});

it('captura a una persona nueva con su consentimiento y evidencia, y le da el cupón una sola vez', function () {
    $w = onsiteWidget($this, 'popup', ['consent_config' => ['channels' => ['whatsapp', 'email']]]);
    $body = ['anonymous_id' => 'anon-pop', 'variant' => 'a', 'page_url' => 'https://tienda.co/', 'fields' => ['phone' => '300 123 4567', 'first_name' => 'Ana', 'email' => 'colado@x.co'],
        'consents' => ['whatsapp' => true, 'email' => true, 'sms' => true]];
    $res = onsiteSubmit($this, $w, $body)->assertOk();
    expect($res->json('ok'))->toBeTrue()
        ->and($res->json('coupon.code'))->toBe('BIENVENIDA-PRUEBA1')
        ->and($res->json('coupon.description'))->toBe('10% de descuento')
        ->and($res->json('coupon.expires_at'))->toBe(now()->addDays(7)->setTimezone('America/Bogota')->toDateString())
        ->and($res->json('message'))->toBe('¡Listo! Tu cupón es BIENVENIDA-PRUEBA1')
        ->and($res->json('double_opt_in'))->toBeFalse()
        ->and($res->headers->get('Access-Control-Allow-Origin'))->toBe('https://tienda.co');
    expect($this->issued[0]->sourceType)->toBe('widget')->and($this->issued[0]->sourceId)->toBe($w)->and($this->issued[0]->siteId)->toBe($this->site->id);

    $contact = onsiteInWs($this, fn () => Contact::query()->firstOrFail());
    expect($contact->first_name)->toBe('Ana')->and($contact->phone)->toBe('+573001234567')->and($contact->email)->toBeNull(); // el popup no pide correo
    $consents = DB::table('consent_events')->where('contact_id', $contact->id)->get();
    expect($consents->pluck('channel')->all())->toBe(['whatsapp'])
        ->and($consents[0]->source)->toBe("widget:{$w}")->and($consents[0]->legal_text_version)->toBe('v1')
        ->and(json_decode($consents[0]->evidence, true))->toHaveKeys(['page_url', 'ip_hash', 'ua_hash'])
        ->and($consents[0]->evidence)->not->toContain('181.50.1.2');
    $capture = onsiteInWs($this, fn () => WidgetCapture::query()->firstOrFail());
    expect($capture->was_known)->toBeFalse()->and($capture->variant)->toBe('a')->and($capture->anonymous_id)->toBe('anon-pop')->and($capture->coupon_id)->not->toBeNull();

    // Otra vez, desde otro navegador y con el número escrito distinto: 409.
    onsiteSubmit($this, $w, ['anonymous_id' => 'otro', 'fields' => ['phone' => '+57 300 1234567']])->assertStatus(409)->assertJsonPath('error', 'already_claimed');
    expect($this->issued)->toHaveCount(1);
});

it('una persona que ya era contacto queda como conocida y el widget sin cupón no menciona el código', function () {
    $w = onsiteWidget($this, 'embedded_form');
    onsiteSubmit($this, $w, ['anonymous_id' => 'a1', 'fields' => ['email' => 'ana@ejemplo.com']])->assertOk();
    $again = onsiteSubmit($this, $w, ['anonymous_id' => 'a2', 'fields' => ['email' => 'ANA@ejemplo.com'], 'consents' => ['email' => true]])->assertOk();
    expect($again->json('message'))->toBe('¡Gracias por suscribirte!')->and($again->json())->not->toHaveKey('coupon');
    $form = onsiteWidget($this, 'popup', ['coupon_config' => ['enabled' => false], 'variants' => [['content' => ['title' => 'Hola', 'cta' => 'Ok', 'success' => '¡Listo! Tu cupón es {{codigo}}', 'fields' => [['key' => 'email', 'required' => true]]]]]]);
    $known = onsiteSubmit($this, $form, ['anonymous_id' => 'a3', 'fields' => ['email' => 'ana@ejemplo.com']])->assertOk();
    expect($known->json('message'))->toBe('¡Listo!');
    $captures = onsiteInWs($this, fn () => WidgetCapture::query()->orderBy('id')->get());
    expect($captures)->toHaveCount(2)->and($captures[0]->was_known)->toBeFalse()->and($captures[1]->was_known)->toBeTrue();
    expect(onsiteInWs($this, fn () => Contact::query()->count()))->toBe(1);
});

it('valida en español, no acepta widgets inactivos o de otro sitio y limita los intentos', function () {
    $w = onsiteWidget($this, 'popup');
    $bad = onsiteSubmit($this, $w, ['anonymous_id' => 'v1', 'fields' => ['phone' => '123']])->assertUnprocessable();
    expect($bad->json('errors.phone.0'))->toStartWith('Escribe un número de WhatsApp válido')->and($bad->json('ok'))->toBeFalse();
    onsiteSubmit($this, $w, ['anonymous_id' => 'v1', 'fields' => []])->assertUnprocessable()->assertJsonPath('errors.phone.0', 'Escribe tu número de WhatsApp.');
    $this->call('POST', "/p/{$this->key}/widgets/{$w}/submit", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '10.0.0.9'], 'no es json')->assertUnprocessable();

    $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w}/pause")->assertOk();
    onsiteSubmit($this, $w, ['anonymous_id' => 'v2', 'fields' => ['phone' => '3001234567']])->assertNotFound()->assertJsonPath('error', 'unknown_widget');
    $wa = onsiteWidget($this, 'wa_widget', ['variants' => [['content' => ['title' => 'Escríbenos', 'cta' => 'Abrir', 'wa' => ['number' => '573001234567']]]]]);
    onsiteSubmit($this, $wa, ['anonymous_id' => 'v2', 'fields' => ['phone' => '3001234567']])->assertNotFound();
    onsiteSubmit($this, 'wg_01JDZZZZZZZZZZZZZZZZZZZZZZ', ['anonymous_id' => 'v2'])->assertNotFound();

    $other = $this->connectedSite($this->ws, 'otra.co')[0];
    $only = onsiteWidget($this, 'popup', ['site_id' => $other->publicId()]);
    onsiteSubmit($this, $only, ['anonymous_id' => 'v3', 'fields' => ['phone' => '3001234567']])->assertNotFound();

    // Límite por navegador (8 por minuto) y por IP con hash (30).
    RateLimiter::clear('x');
    $w2 = onsiteWidget($this, 'embedded_form');
    for ($i = 0; $i < 8; $i++) {
        onsiteSubmit($this, $w2, ['anonymous_id' => 'bot', 'fields' => ['email' => "x{$i}@ejemplo.com"]], ['REMOTE_ADDR' => '10.1.1.'.$i]);
    }
    onsiteSubmit($this, $w2, ['anonymous_id' => 'bot', 'fields' => ['email' => 'y@ejemplo.com']], ['REMOTE_ADDR' => '10.2.2.2'])->assertStatus(429);
    onsiteSubmit($this, $w2, ['anonymous_id' => 'humano', 'fields' => ['email' => 'z@ejemplo.com']], ['REMOTE_ADDR' => '10.2.2.2'])->assertOk();
});

it('la ruleta sortea el premio en el hub, solo con premios configurados, y una sola vez por persona', function () {
    $w = onsiteWidget($this, 'spin_wheel', ['coupon_config' => ['prizes' => [
        ['key' => 'nada', 'label' => 'Sigue intentando', 'weight' => 0, 'coupon' => null],
        ['key' => 'p15', 'label' => '15%', 'weight' => 10, 'coupon' => ['kind' => 'percent', 'amount' => 15, 'expires_in_days' => 2, 'prefix' => 'RULETA']],
    ]]]);
    $res = onsiteSubmit($this, $w, ['anonymous_id' => 'r1', 'fields' => ['phone' => '3001234567'], 'consents' => ['whatsapp' => true]])->assertOk();
    expect($res->json('prize'))->toBe(['key' => 'p15', 'label' => '15%'])
        ->and($res->json('coupon.code'))->toBe('RULETA-PRUEBA1')->and($res->json('message'))->toBe('¡Ganaste 15%! Tu cupón es RULETA-PRUEBA1');
    onsiteSubmit($this, $w, ['anonymous_id' => 'r2', 'fields' => ['phone' => '3001234567']])->assertStatus(409)->assertJsonPath('error', 'already_played');

    $none = onsiteWidget($this, 'spin_wheel', ['coupon_config' => ['prizes' => [
        ['key' => 'nada', 'label' => 'Casi', 'weight' => 5, 'coupon' => null], ['key' => 'cero', 'label' => 'Nunca', 'weight' => 0, 'coupon' => null],
    ]]]);
    $lose = onsiteSubmit($this, $none, ['anonymous_id' => 'r3', 'fields' => ['phone' => '3109998877']])->assertOk();
    expect($lose->json('prize.key'))->toBe('nada')->and($lose->json())->not->toHaveKey('coupon')
        ->and($lose->json('message'))->toBe('Esta vez no hubo premio. ¡Gracias por participar!');
});

it('si el sitio no crea el cupón, la persona queda capturada y puede pedirlo otra vez', function () {
    $w = onsiteWidget($this, 'popup');
    $this->couponFails = true;
    $res = onsiteSubmit($this, $w, ['anonymous_id' => 'c1', 'fields' => ['phone' => '3001234567']])->assertOk();
    expect($res->json())->not->toHaveKey('coupon')->and($res->json('message'))->toContain('No pudimos generar tu cupón');
    $this->couponFails = false;
    expect(onsiteSubmit($this, $w, ['anonymous_id' => 'c1', 'fields' => ['phone' => '3001234567']])->assertOk()->json('coupon.code'))->toBe('BIENVENIDA-PRUEBA1');
    expect(onsiteInWs($this, fn () => WidgetCapture::query()->count()))->toBe(1);
});

it('el quiz guarda las respuestas como atributos y responde con los productos de la regla', function () {
    foreach ([['88', 'Vinilo tipo 1', '12', 'Pintuco', '150000.00', 'instock', 9], ['89', 'Esmalte', '12', 'Sika', '90000.00', 'instock', 5], ['90', 'Brocha', '30', 'Goya', '15000.00', 'instock', 1], ['91', 'Agotado', '12', 'Pintuco', '1000.00', 'outofstock', 50]] as [$id, $name, $cat, $brand, $price, $stock, $sold]) {
        DB::table('products')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => $id, 'type' => 'simple',
            'name' => $name, 'permalink' => "https://tienda.co/p/{$id}", 'category_ids' => json_encode([$cat]), 'brand' => $brand, 'price' => $price, 'currency' => 'COP',
            'stock_status' => $stock, 'status' => 'publish', 'content_hash' => md5($id), 'purchases_30d' => $sold, 'created_at' => now(), 'updated_at' => now()]);
    }

    $w = onsiteWidget($this, 'quiz', ['quiz' => [
        'questions' => [
            ['id' => 'q1', 'text' => '¿Interior o exterior?', 'attribute' => 'quiz_ambiente', 'options' => [
                ['key' => 'interior', 'label' => 'Interior', 'next' => 'q2', 'attributes' => ['pinta_interiores' => 'si']], ['key' => 'exterior', 'label' => 'Exterior', 'next' => 'end'],
            ]],
            ['id' => 'q2', 'text' => '¿Presupuesto?', 'attribute' => 'quiz_presupuesto', 'options' => [['key' => 'bajo', 'label' => 'Bajo'], ['key' => 'alto', 'label' => 'Alto']]],
        ],
        'outcomes' => ['rules' => [
            ['label' => 'Interior económico', 'match' => ['q1' => ['interior'], 'q2' => ['bajo']], 'products' => ['category_ids' => ['12'], 'max_price' => 100000], 'message' => 'Para ti: pinturas económicas'],
            ['label' => 'Pintuco', 'match' => ['q1' => ['interior']], 'products' => ['brands' => ['Pintuco']]],
        ], 'fallback' => ['products' => ['product_ids' => ['90']], 'message' => 'Mira esto'], 'limit' => 3],
    ]]);

    $res = onsiteSubmit($this, $w, ['anonymous_id' => 'q-anon', 'answers' => ['q1' => 'interior', 'q2' => 'bajo'], 'fields' => ['email' => 'luz@ejemplo.com', 'first_name' => 'Luz'], 'consents' => ['email' => true]])->assertOk();
    expect($res->json('message'))->toBe('Para ti: pinturas económicas')
        ->and(array_column($res->json('products'), 'id'))->toBe(['89'])
        ->and($res->json('products.0'))->toMatchArray(['name' => 'Esmalte', 'price' => '90000.00', 'currency' => 'COP', 'url' => 'https://tienda.co/p/89']);
    $contact = onsiteInWs($this, fn () => Contact::query()->firstOrFail());
    expect($contact->custom_attributes)->toMatchArray(['quiz_ambiente' => 'interior', 'quiz_presupuesto' => 'bajo', 'pinta_interiores' => 'si']);
    expect(onsiteInWs($this, fn () => WidgetCapture::query()->firstOrFail())->answers)->toBe(['q1' => 'interior', 'q2' => 'bajo']);

    $second = onsiteSubmit($this, $w, ['anonymous_id' => 'q2', 'answers' => ['q1' => 'interior', 'q2' => 'alto'], 'fields' => ['email' => 'sol@ejemplo.com']])->assertOk();
    expect(array_column($second->json('products'), 'id'))->toBe(['88']);   // sin agotados
    $third = onsiteSubmit($this, $w, ['anonymous_id' => 'q3', 'answers' => ['q1' => 'exterior'], 'fields' => ['email' => 'mar@ejemplo.com']])->assertOk();
    expect(array_column($third->json('products'), 'id'))->toBe(['90'])->and($third->json('message'))->toBe('Mira esto');
    onsiteSubmit($this, $w, ['anonymous_id' => 'q4', 'answers' => ['q1' => 'marte'], 'fields' => ['email' => 'x@ejemplo.com']])->assertUnprocessable()->assertJsonValidationErrors('answers');
});

it('el formulario en 2 pasos une el correo y el WhatsApp en el mismo contacto y no repite el cupón', function () {
    $w = onsiteWidget($this, 'two_step');
    $one = onsiteSubmit($this, $w, ['anonymous_id' => 'dos', 'step' => 1, 'fields' => ['email' => 'pao@ejemplo.com'], 'consents' => ['email' => true]])->assertOk();
    expect($one->json('next_step'))->toBe(2)->and($one->json('coupon.code'))->not->toBeNull();
    $two = onsiteSubmit($this, $w, ['anonymous_id' => 'dos', 'step' => 2, 'fields' => ['phone' => '3157654321'], 'consents' => ['whatsapp' => true]])->assertOk();
    expect($two->json())->not->toHaveKey('coupon')->not->toHaveKey('next_step')->and($two->json('message'))->toBe('¡Listo! Ya estás en la lista.');
    $contacts = onsiteInWs($this, fn () => Contact::query()->whereNull('merged_into_id')->get());
    expect($contacts)->toHaveCount(1)->and($contacts[0]->email)->toBe('pao@ejemplo.com')->and($contacts[0]->phone)->toBe('+573157654321');
    expect(DB::table('contact_consents')->where('contact_id', $contacts[0]->id)->where('status', 'granted')->pluck('channel')->sort()->values()->all())->toBe(['email', 'whatsapp']);
    expect(onsiteInWs($this, fn () => WidgetCapture::query()->count()))->toBe(1)->and($this->issued)->toHaveCount(1);
});

it('con el código de referido da el regalo del amigo (M14)', function () {
    $w = onsiteWidget($this, 'referral_welcome');
    $res = onsiteSubmit($this, $w, ['anonymous_id' => 'amigo', 'fields' => ['phone' => '3001112233', 'first_name' => 'Leo'], 'referral_code' => 'ANA7K2'])->assertOk();
    expect($res->json('coupon.code'))->toBe('AMIGO-X1')->and($res->json('message'))->toBe('¡Listo! Tu cupón es AMIGO-X1');
    expect($this->referralCalls[0])->toMatchArray(['code' => 'ANA7K2', 'anonymousId' => 'amigo', 'wasKnown' => false, 'siteId' => $this->site->id, 'ip' => '181.50.1.2']);

    // En un widget con su propio cupón, el del referido llega aparte; un código inválido no llama a M14.
    $popup = onsiteWidget($this, 'popup');
    $both = onsiteSubmit($this, $popup, ['anonymous_id' => 'amigo2', 'fields' => ['phone' => '3004445566'], 'referral_code' => 'ANA7K2'])->assertOk();
    expect($both->json('coupon.code'))->toStartWith('BIENVENIDA-')->and($both->json('referral_coupon.code'))->toBe('AMIGO-X2');
    onsiteSubmit($this, $popup, ['anonymous_id' => 'amigo3', 'fields' => ['phone' => '3007778899'], 'referral_code' => '<script>'])->assertOk();
    expect($this->referralCalls)->toHaveCount(2);
});

it('el doble opt-in deja el correo pendiente, envía la confirmación y la página la convierte en consentimiento', function () {
    $w = onsiteWidget($this, 'embedded_form', ['consent_config' => ['channels' => ['email'], 'double_opt_in' => true]]);
    $res = onsiteSubmit($this, $w, ['anonymous_id' => 'doi', 'fields' => ['email' => 'eva@ejemplo.com'], 'consents' => ['email' => true]])->assertOk();
    expect($res->json('double_opt_in'))->toBeTrue()->and($res->json('message'))->toContain('confirma tu suscripción');
    $contact = onsiteInWs($this, fn () => Contact::query()->firstOrFail());
    expect(DB::table('contact_consents')->where('contact_id', $contact->id)->value('status'))->toBe('pending_double_optin');

    expect($this->messages->dispatched)->toHaveCount(1);
    /** @var OutboundMessage $mail */
    $mail = $this->messages->dispatched[0];
    expect($mail->kind)->toBe(MessageKind::Transactional)->and($mail->contactId)->toBe($contact->id)->and($mail->context['subject'])->toContain('Confirma tu suscripción');
    $url = $mail->context['enlaces']['confirmar'];
    expect($url)->toContain("/p/{$this->key}/confirmar/");
    $path = parse_url($url, PHP_URL_PATH);

    $page = $this->get($path)->assertOk()->assertSee('Confirmaste tu suscripción');
    expect($page->headers->get('Cache-Control'))->toContain('no-store');
    $consent = DB::table('contact_consents')->where('contact_id', $contact->id)->first();
    expect($consent->status)->toBe('granted')->and($consent->source)->toBe("widget:{$w}")->and($consent->legal_text_version)->toBe('v1');
    $event = DB::table('consent_events')->where('contact_id', $contact->id)->where('action', 'granted')->first();
    expect(json_decode($event->evidence, true))->toMatchArray(['double_opt_in' => true, 'widget_id' => $w]);

    $this->get($path)->assertOk()->assertSee('ya estaba confirmada');
    $this->get("/p/{$this->key}/confirmar/abc.".str_repeat('0', 32))->assertNotFound()->assertSee('Este enlace ya no funciona');
});

it('no abre CORS a otros dominios ni deja usar un widget con la clave de otro espacio de trabajo', function () {
    $w = onsiteWidget($this, 'popup');
    $res = $this->call('GET', "/p/{$this->key}/widgets.json?page_type=home", [], [], [], ['HTTP_ORIGIN' => 'https://atacante.co'])->assertOk();
    expect($res->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    $this->call('OPTIONS', "/p/{$this->key}/widgets/{$w}/submit", [], [], [], ['HTTP_ORIGIN' => 'https://tienda.co'])
        ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://tienda.co');

    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    [$otherSite] = $this->connectedSite($other, 'otra-tienda.co');
    $this->call('POST', "/p/{$otherSite->public_key}/widgets/{$w}/submit", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '10.9.9.9'],
        json_encode(['anonymous_id' => 'x', 'fields' => ['phone' => '3001234567']]))->assertNotFound();
    expect($this->getJson("/p/{$otherSite->public_key}/widgets.json?page_type=home")->json('widgets'))->toBe([]);
    expect(DB::table('contacts')->count())->toBe(0);
});
