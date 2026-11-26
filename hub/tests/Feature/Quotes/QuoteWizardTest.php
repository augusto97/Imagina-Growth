<?php

use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Quotes\Models\QuoteCalculator;
use App\Domain\Quotes\Support\QuoteAssistant;
use App\Domain\Quotes\Support\QuoteEngine;
use App\Domain\Quotes\Support\QuotePage;
use App\Domain\Quotes\Support\QuoteTemplates;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;

require_once __DIR__.'/QuoteFixtures.php';

/*
 * El asistente del cotizador (M18): plantillas por oficio con precios en la moneda del negocio, ejemplos de cálculo
 * sin guardar, «Descríbelo y la IA lo arma» (sin precios inventados y sin guardar), la vista previa de la página
 * pública y el enlace y el QR de la página.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    quoteFakeLeads();
    $this->base = "/api/w/{$this->ws->publicId()}/quotes";
});

/** Una propuesta de la IA como la devuelve el esquema. */
function quoteAiProposal(array $overrides = []): array
{
    return array_replace([
        'name' => 'Pintura de casas', 'kind' => 'area', 'intro' => 'Cotiza en un minuto.', 'note' => null,
        'base_price' => 0, 'minimum' => 200000, 'range_pct' => 10,
        'fields' => [
            ['label' => '¿Cuántos m² vas a pintar?', 'type' => 'number', 'icon' => '📐', 'help' => null, 'unit' => 'm²', 'unit_price' => 15000,
                'min' => 1, 'max' => 3000, 'step' => 1, 'options' => []],
            ['label' => '¿Dónde?', 'type' => 'select', 'icon' => '🏠', 'help' => null, 'unit' => null, 'unit_price' => 0, 'min' => 0, 'max' => null, 'step' => 1,
                'options' => [
                    ['label' => 'Interior', 'icon' => '🛋️', 'description' => null, 'price' => 0, 'multiplier' => 1],
                    ['label' => 'Exterior', 'icon' => '🌦️', 'description' => null, 'price' => 0, 'multiplier' => 1.3],
                    ['label' => 'Fachada alta', 'icon' => '🏢', 'description' => null, 'price' => 90000, 'multiplier' => 1.5],
                ]],
        ],
        'packages' => [],
    ], $overrides);
}

it('cada plantilla por oficio es válida en pesos colombianos, mexicanos y dólares', function (string $currency) {
    $templates = QuoteTemplates::all($currency);
    expect(array_column($templates, 'key'))->toBe(QuoteTemplates::KEYS);
    foreach ($templates as $t) {
        $def = QuoteEngine::normalize($t['definition'], $t['kind']);
        expect($t['icon'])->not->toBe('')->and($t['name'])->not->toBe('');
        // Con las respuestas de ejemplo da un rango positivo.
        $calc = new QuoteCalculator(['kind' => $t['kind']]);
        $calc->definition = $def;
        $estimate = QuoteEngine::estimate($calc, QuotePage::sampleAnswers($def));
        expect((float) $estimate['max'])->toBeGreaterThan(0);
    }
})->with(['COP', 'MXN', 'USD']);

it('las plantillas llegan con precios en la moneda del espacio de trabajo y con sus emojis', function () {
    $res = $this->actingAs($this->owner)->getJson("{$this->base}/templates")->assertOk()->assertJsonPath('currency', 'COP')->assertJsonCount(10, 'data');
    $paint = collect($res->json('data'))->firstWhere('key', 'pintura');
    expect($paint['kind'])->toBe('area')->and($paint['definition']['fields'][0]['unit_price'])->toEqual(14000)
        ->and($paint['definition']['fields'][1]['options'][1])->toMatchArray(['label' => 'Lavable premium', 'icon' => '✨', 'multiplier' => 1.25])
        ->and($paint['definition']['round_to'])->toEqual(1000);

    $this->ws->forceFill(['currency' => 'MXN'])->save();
    $mx = collect($this->actingAs($this->owner)->getJson("{$this->base}/templates")->json('data'))->firstWhere('key', 'pintura');
    expect($mx['definition']['fields'][0]['unit_price'])->toEqual(63)->and($mx['definition']['round_to'])->toEqual(10);
    expect(QuoteTemplates::price(0.6, 'COP'))->toBe(2400.0)->and(QuoteTemplates::price(1500, 'COP'))->toBe(6000000.0)->and(QuoteTemplates::price(3.5, 'USD'))->toBe(3.5);
});

it('calcula los ejemplos del asistente con una definición sin guardar', function () {
    $def = quoteAreaDefinition(['minimum' => 0, 'range_pct' => 0, 'base_price' => 0]);
    $res = $this->actingAs($this->owner)->postJson("{$this->base}/preview", ['kind' => 'area', 'definition' => $def, 'scenarios' => [
        ['area' => 10, 'tipo' => 'vinilo'], ['area' => 40, 'tipo' => 'esmalte', 'extras' => ['techo', 'resanes']], ['area' => 1],
    ]])->assertOk();
    expect($res->json('data.0'))->toMatchArray(['ok' => true, 'mid' => '120000.00'])
        ->and($res->json('data.1.mid'))->toBe('850000.00')
        ->and($res->json('data.2.ok'))->toBeFalse()->and($res->json('data.2.message'))->toContain('5');
    $this->actingAs($this->owner)->postJson("{$this->base}/preview", ['kind' => 'area', 'definition' => $def, 'scenarios' => []])->assertUnprocessable();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => QuoteCalculator::query()->count()))->toBe(0);
});

it('la IA arma el cotizador sin inventar precios y nunca lo guarda', function () {
    $ai = FakeAiGateway::install()->replyJson(quoteAiProposal());
    $res = $this->actingAs($this->owner)->postJson("{$this->base}/assistant", [
        'description' => 'Pinto casas y apartamentos en Medellín. Cobro 15 mil el metro cuadrado y en exteriores un 30% más. Mínimo $200.000.',
    ])->assertOk()->assertJsonPath('kind', 'area')->assertJsonPath('name', 'Pintura de casas');
    $def = $res->json('definition');
    // Escritos en la descripción: se quedan.
    expect($def['fields'][0]['unit_price'])->toEqual(15000)->and($def['minimum'])->toEqual(200000)
        ->and($def['fields'][1]['options'][1]['multiplier'])->toEqual(1.3)->and($def['fields'][1]['options'][1]['icon'])->toBe('🌦️');
    // Inventados: a cero o a 1, y marcados para que la persona los ponga.
    expect($def['fields'][1]['options'][2]['price'])->toEqual(0)->and($def['fields'][1]['options'][2]['multiplier'])->toEqual(1)
        ->and($res->json('needs_price'))->toBe(['fields.1.options.2.price', 'fields.1.options.2.multiplier'])
        ->and($def['round_to'])->toEqual(1000);
    expect($ai->requests[0]['request']->feature)->toBe('copilot')->and($ai->requests[0]['request']->outputSchema)->toBe(QuoteAssistant::schema());
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => QuoteCalculator::query()->count()))->toBe(0);
});

it('sin precios en la descripción, el cotizador por área queda para que la persona ponga el precio', function () {
    FakeAiGateway::install()->replyJson(quoteAiProposal(['minimum' => 0]));
    $res = $this->actingAs($this->owner)->postJson("{$this->base}/assistant", ['description' => 'Pinto casas y apartamentos, interiores y fachadas.'])->assertOk();
    expect($res->json('definition.fields.0.unit_price'))->toEqual(0)->and($res->json('needs_price'))->toContain('fields.0.unit_price');
    // Guardarlo así no pasa: el cotizador por área necesita su precio por unidad.
    $this->actingAs($this->owner)->postJson("{$this->base}/calculators", ['name' => 'X', 'kind' => 'area', 'definition' => $res->json('definition')])
        ->assertUnprocessable()->assertJsonValidationErrors('definition.fields');
});

it('si la propuesta no es válida se le pide corregirla una vez; si sigue mal, se descarta', function () {
    $bad = quoteAiProposal(['kind' => 'package', 'packages' => []]);
    $ai = FakeAiGateway::install()->replyJson($bad)->replyJson(quoteAiProposal());
    $this->actingAs($this->owner)->postJson("{$this->base}/assistant", ['description' => 'Pinto casas. Cobro 15 mil el metro cuadrado.'])->assertOk()->assertJsonPath('kind', 'area');
    expect($ai->requests)->toHaveCount(2)->and(json_encode($ai->requests[1]['request']->messages, JSON_UNESCAPED_UNICODE))->toContain('definition.packages');

    FakeAiGateway::install()->replyJson($bad)->replyJson($bad);
    $this->actingAs($this->owner)->postJson("{$this->base}/assistant", ['description' => 'Pinto casas. Cobro 15 mil el metro cuadrado.'])
        ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'IA'));
});

it('sin IA dice por qué y solo quien administra la usa', function () {
    FakeAiGateway::install()->failWith('disabled');
    $this->actingAs($this->owner)->postJson("{$this->base}/assistant", ['description' => 'Pinto casas. Cobro 15 mil el metro cuadrado.'])
        ->assertStatus(503)->assertJsonPath('reason', 'disabled');
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->postJson("{$this->base}/assistant", ['description' => 'Pinto casas. Cobro 15 mil el metro cuadrado.'])->assertForbidden();
    $this->actingAs($this->owner)->postJson("{$this->base}/assistant", ['description' => 'corto'])->assertUnprocessable();
});

it('lee las cifras y los porcentajes escritos en la descripción', function (string $text, array $money, array $percents) {
    expect(QuoteAssistant::numbers($text))->toEqual(['money' => $money, 'percents' => $percents]);
})->with([
    'mil' => ['cobro 15 mil el metro', [15000.0], []],
    'k y porcentaje' => ['15k y 30% más', [15000.0], [30.0]],
    'miles con punto' => ['mínimo $80.000', [80000.0], []],
    'millones' => ['1,5 millones el paquete', [1500000.0], []],
    'por ciento' => ['un 20 por ciento menos', [], [20.0]],
    'unidad sin escala' => ['40 m² a 3,5 dólares', [40.0, 3.5], []],
]);

it('la vista previa de la página pública muestra el borrador y su resultado sin guardar nada', function () {
    $draft = ['name' => 'Pintura de prueba', 'kind' => 'area', 'definition' => quoteAreaDefinition(['intro' => 'Responde y mira tu precio.']),
        'capture' => ['required' => false]];
    $url = $this->actingAs($this->owner)->postJson("{$this->base}/page-preview", $draft)->assertOk()->json('url');
    expect($url)->toStartWith("/api/w/{$this->ws->publicId()}/quotes/page-preview/");

    $html = $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN')->getContent();
    expect($html)->toContain('Pintura de prueba')->toContain('Responde y mira tu precio.')->toContain('Vista previa')
        ->toContain('$12.000 por m²')->toContain('+25 %')->toContain('method="get"');

    $result = $this->actingAs($this->owner)->get($url.'/result?'.http_build_query(['a' => ['area' => 40, 'tipo' => 'esmalte', 'extras' => ['techo']]]))->assertOk()->getContent();
    expect($result)->toContain('$686.000 – $839.000 COP')->toContain('Techo');
    $this->actingAs($this->owner)->get($url.'/result?'.http_build_query(['a' => ['area' => 1, 'tipo' => 'vinilo']]))->assertStatus(422);

    // Otro espacio de trabajo no ve el borrador.
    $other = $this->userWith2fa();
    $ws2 = $this->workspaceFor($other);
    $this->actingAs($other)->get(str_replace($this->ws->publicId(), $ws2->publicId(), $url))->assertNotFound();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => QuoteCalculator::query()->count()))->toBe(0);
});

it('cada cotizador trae el enlace de su página y su QR', function () {
    $calc = quoteCalculator($this->ws);
    $c = $this->actingAs($this->owner)->getJson("{$this->base}/calculators/{$calc->publicId()}")->assertOk()->json();
    expect($c['page_url'])->toBe(rtrim((string) config('app.url'), '/')."/p/{$this->site->public_key}/cotizar/{$calc->publicId()}");
    $svg = $this->actingAs($this->owner)->get("{$this->base}/calculators/{$calc->publicId()}/qr?format=svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($svg->getContent())->toContain('<svg');
    $this->actingAs($this->owner)->get("{$this->base}/calculators/{$calc->publicId()}/qr?format=png&download=1")->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="qr-cotizador.png"');
    expect($this->actingAs($this->owner)->getJson("{$this->base}/calculators")->json('ai'))->toBeBool();
});
