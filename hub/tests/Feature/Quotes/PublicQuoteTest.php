<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/QuoteFixtures.php';

/*
 * El cotizador en la tienda (M18, api-connector.md §8.3): definición pública sin
 * notas internas ni precios (si el precio se da a cambio del contacto), cálculo
 * en el hub, contacto, lead, quote.requested y límites.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->leads = quoteFakeLeads();
    $this->calc = quoteCalculator($this->ws);
    $this->url = "/p/{$this->site->public_key}/quotes/{$this->calc->publicId()}";
});

function quotePost($test, string $url, array $body): TestResponse
{
    return $test->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode($body));
}

function quoteBody(array $overrides = []): array
{
    return array_replace([
        'anonymous_id' => '6f0c8a2e-1b3d-4e5f-8a9b-0c1d2e3f4a5b', 'page_url' => 'https://tienda.co/cotizar',
        'answers' => ['area' => 40, 'tipo' => 'esmalte', 'extras' => ['techo']],
        'fields' => ['first_name' => 'Ana', 'phone' => '300 123 4567', 'email' => 'ana@example.com'],
        'consents' => ['whatsapp' => true, 'email' => false], 'utm' => ['utm_source' => 'google', 'gclid' => 'xyz'],
    ], $overrides);
}

it('la definición pública no trae notas internas ni precios si pide el contacto', function () {
    $res = $this->getJson($this->url, ['Origin' => 'https://tienda.co'])->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, public')->assertHeader('Access-Control-Allow-Origin', 'https://tienda.co')
        ->assertJsonPath('calculator.id', $this->calc->publicId())->assertJsonPath('calculator.capture.required', true)
        ->assertJsonPath('calculator.currency', 'COP')->assertJsonPath('calculator.definition.note', 'Incluye materiales.');
    $json = json_encode($res->json());
    expect($json)->not->toContain('Margen')->not->toContain('internal_notes')->not->toContain('unit_price')->not->toContain('multiplier')
        ->not->toContain('12000');
    expect($res->json('calculator.definition.fields.1.options.1'))->toBe(['value' => 'esmalte', 'label' => 'Esmalte']);

    // Sin captura obligatoria, los precios viajan para el cálculo en vivo.
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->calc->forceFill(['capture' => ['required' => false] + QuoteCapture::defaults()])->save());
    expect($this->getJson($this->url)->json('calculator.definition.fields.0.unit_price'))->toEqual(12000);
});

it('un cotizador en borrador, de otro sitio o inventado no existe', function () {
    $draft = quoteCalculator($this->ws, ['status' => 'draft']);
    [$other] = $this->connectedSite($this->ws, 'otra.co');
    $mine = quoteCalculator($this->ws, ['site_id' => $other->id]);
    $this->getJson("/p/{$this->site->public_key}/quotes/{$draft->publicId()}")->assertNotFound()->assertJsonPath('error.code', 'unknown_calculator');
    $this->getJson("/p/{$this->site->public_key}/quotes/{$mine->publicId()}")->assertNotFound();
    $this->getJson("/p/{$other->public_key}/quotes/{$mine->publicId()}")->assertOk();
    $this->getJson('/p/pk_'.str_repeat('0', 32)."/quotes/{$this->calc->publicId()}")->assertNotFound();
});

it('calcula a cambio del contacto: cotización, lead, consentimiento y quote.requested', function () {
    $res = quotePost($this, "{$this->url}/estimate", quoteBody())->assertOk()
        ->assertJsonPath('ok', true)->assertJsonPath('estimate.min', '686000.00')->assertJsonPath('estimate.max', '839000.00')
        ->assertJsonPath('estimate.text', '$686.000 – $839.000 COP')->assertHeader('Cache-Control', 'no-store, private');
    expect(json_encode($res->json()))->not->toContain('ana@example.com')->not->toContain('3001234567');

    [$quote, $contact] = app(CurrentWorkspace::class)->run($this->ws, fn () => [Quote::query()->firstOrFail(), Contact::query()->firstOrFail()]);
    expect($quote->contact_id)->toBe($contact->id)->and($quote->lead_id)->toHaveLength(26)->and((string) $quote->estimate_min)->toBe('686000.00')
        ->and($quote->answers)->toBe(['area' => 40, 'tipo' => 'esmalte', 'extras' => ['techo']])->and($res->json('quote_id'))->toBe($quote->publicId());
    expect(DB::table('contact_consents')->where('contact_id', $contact->id)->where('status', 'granted')->pluck('channel')->all())->toBe(['whatsapp']);

    $lead = $this->leads->captured[0];
    expect($lead->origin)->toBe('quote')->and($lead->originRef)->toBe($quote->publicId())->and($lead->value)->toBe('763000.00')
        ->and($lead->service)->toBe('Pintura de interiores')->and($lead->source)->toMatchArray(['utm_source' => 'google', 'gclid' => 'xyz']);

    $rows = $this->chRows($this->ws, "SELECT toJSONString(properties) AS p, contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'quote.requested'");
    expect($rows)->toHaveCount(1);
    $props = json_decode($rows[0]['p']);
    expect(app(SchemaRegistry::class)->validateProperties('quote.requested', $props))->toBeNull()
        ->and($props->estimate_max)->toBe('839000.00')->and($props->lead_id)->toStartWith('ld_');
});

it('sin datos de contacto no da el precio cuando lo pide', function () {
    quotePost($this, "{$this->url}/estimate", quoteBody(['fields' => ['first_name' => 'Ana']]))->assertStatus(422)
        ->assertJsonPath('ok', false)->assertJsonPath('errors', fn ($e) => isset($e['fields.phone']));
    quotePost($this, "{$this->url}/estimate", quoteBody(['answers' => ['area' => 1, 'tipo' => 'vinilo']]))->assertStatus(422)
        ->assertJsonPath('errors', fn ($e) => isset($e['answers.area']));
    quotePost($this, "{$this->url}/estimate", quoteBody(['fields' => ['phone' => '12']]))->assertStatus(422);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Quote::query()->count()))->toBe(0);
});

it('sin captura obligatoria calcula anónimo, sin lead ni evento', function () {
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->calc->forceFill(['capture' => ['required' => false] + QuoteCapture::defaults()])->save());
    quotePost($this, "{$this->url}/estimate", quoteBody(['fields' => []]))->assertOk()->assertJsonPath('estimate.min', '686000.00');
    $quote = app(CurrentWorkspace::class)->run($this->ws, fn () => Quote::query()->firstOrFail());
    expect($quote->contact_id)->toBeNull()->and($quote->anonymous_id)->toBe('6f0c8a2e-1b3d-4e5f-8a9b-0c1d2e3f4a5b')->and($this->leads->captured)->toBe([]);
    expect($this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'quote.requested'"))->toBe([]);
});

it('limita por IP y por anonymous_id', function () {
    for ($i = 0; $i < 10; $i++) {
        quotePost($this, "{$this->url}/estimate", quoteBody(['answers' => []]))->assertStatus(422);
    }
    quotePost($this, "{$this->url}/estimate", quoteBody())->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    // Otro anonymous_id sigue hasta el límite por IP (20).
    quotePost($this, "{$this->url}/estimate", quoteBody(['anonymous_id' => '7f0c8a2e-1b3d-4e5f-8a9b-0c1d2e3f4a5b']))->assertOk();
});

it('con número de WhatsApp ofrece seguir la conversación con la referencia', function () {
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->calc->forceFill(['capture' => ['whatsapp_number' => '573001112233'] + QuoteCapture::defaults()])->save());
    $wa = quotePost($this, "{$this->url}/estimate", quoteBody())->assertOk()->json('whatsapp');
    expect($wa)->toStartWith('https://wa.me/573001112233?text=')->and(rawurldecode($wa))->toContain('Pintura de interiores')->toContain('Ref: qt_');
});
