<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Support\QuoteCapture;
use App\Domain\Quotes\Support\QuotePage;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/QuoteFixtures.php';

/*
 * La página del cotizador en el hub (M18): `/p/{clave}/cotizar/{qc_…}` para compartir por enlace o QR sin WordPress.
 * Una pregunta por paso (con JS), el precio calculado en el hub, el contacto con sus casillas sin marcar, el lead y el
 * resultado con un enlace firmado; sin sesión ni CSRF, con campo trampa y límites por IP.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->leads = quoteFakeLeads();
    $this->calc = quoteCalculator($this->ws, ['definition' => quoteAreaDefinition([
        'intro' => 'Tres preguntas y ves tu precio.',
        'fields' => [
            ['key' => 'area', 'label' => 'Área a pintar', 'type' => 'number', 'icon' => '📐', 'unit' => 'm²', 'min' => 5, 'max' => 2000, 'unit_price' => 12000, 'default' => 40],
            ['key' => 'tipo', 'label' => 'Tipo de pintura', 'type' => 'select', 'options' => [
                ['value' => 'vinilo', 'label' => 'Vinilo', 'icon' => '🏠', 'description' => 'Interiores', 'price' => 0, 'multiplier' => 1],
                ['value' => 'esmalte', 'label' => 'Esmalte', 'icon' => '✨', 'price' => 0, 'multiplier' => 1.25],
            ]],
            ['key' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'options' => [
                ['value' => 'techo', 'label' => 'Techo', 'price' => 80000],
                ['value' => 'resanes', 'label' => 'Resanes', 'price' => 120000],
            ]],
        ],
    ])]);
    $this->url = "/p/{$this->ws->publicId()}/cotizar/{$this->calc->publicId()}";
});

function quotePageBody(array $overrides = []): array
{
    return array_replace([
        'a' => ['area' => '40', 'tipo' => 'esmalte', 'extras' => ['techo']],
        'c' => ['first_name' => 'Ana', 'phone' => '300 123 4567', 'email' => 'ana@example.com'],
        'consent' => ['whatsapp' => '1'],
        'utm' => ['utm_source' => 'qr', 'utm_medium' => 'qr'],
    ], $overrides);
}

it('muestra las preguntas en pasos, con la marca, sin caché, sin índice y con CSP con nonce', function () {
    $res = $this->get($this->url.'?utm_source=qr&utm_medium=qr')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $html = $res->getContent();
    preg_match("/script-src 'nonce-([A-Za-z0-9]+)'/", (string) $res->headers->get('Content-Security-Policy'), $m);
    expect($m[1] ?? null)->not->toBeNull()->and($html)->toContain('<script nonce="'.$m[1].'">');
    expect($html)->toContain('Pintura de interiores')->toContain('Tres preguntas y ves tu precio.')->toContain('data-step')
        ->toContain('✨')->toContain('Interiores')->toContain('name="a[extras][]"')->toContain('name="website"')
        ->toContain('value="qr"')->toContain('¿A dónde te enviamos tu cotización?');
    // El precio se da a cambio del contacto: nada de precios en la página, ni las notas internas.
    expect($html)->not->toContain('12.000')->not->toContain('+25 %')->not->toContain('Margen');
    // Con la clave pública del sitio también abre.
    $this->get("/p/{$this->site->public_key}/cotizar/{$this->calc->publicId()}")->assertOk();
});

it('sin captura obligatoria la página muestra cuánto suma cada opción y no pide el contacto', function () {
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->calc->forceFill(['capture' => ['required' => false] + QuoteCapture::defaults()])->save());
    $html = $this->get($this->url)->assertOk()->getContent();
    expect($html)->toContain('$12.000 por m²')->toContain('+25 %')->toContain('+ $80.000')->not->toContain('¿A dónde te enviamos tu cotización?');

    $res = $this->post($this->url, quotePageBody(['c' => []]))->assertStatus(303);
    $quote = app(CurrentWorkspace::class)->run($this->ws, fn () => Quote::query()->firstOrFail());
    expect($quote->contact_id)->toBeNull()->and($this->leads->captured)->toBe([]);
    $this->get((string) $res->headers->get('Location'))->assertOk()->assertSee('$686.000 – $839.000 COP');
});

it('calcula a cambio del contacto: cotización, lead, consentimiento y el resultado con su enlace firmado', function () {
    $res = $this->post($this->url, quotePageBody())->assertStatus(303);
    [$quote, $contact] = app(CurrentWorkspace::class)->run($this->ws, fn () => [Quote::query()->firstOrFail(), Contact::query()->firstOrFail()]);
    $location = (string) $res->headers->get('Location');
    expect($location)->toBe(rtrim((string) config('app.url'), '/').$this->url.'/r/'.$quote->publicId().'/'.QuotePage::signature($quote));
    expect($quote->contact_id)->toBe($contact->id)->and($quote->answers)->toBe(['area' => 40, 'tipo' => 'esmalte', 'extras' => ['techo']])
        ->and((string) $quote->estimate_min)->toBe('686000.00');
    expect(DB::table('contact_consents')->where('contact_id', $contact->id)->where('status', 'granted')->pluck('channel')->all())->toBe(['whatsapp']);
    $lead = $this->leads->captured[0];
    expect($lead->origin)->toBe('quote')->and($lead->originRef)->toBe($quote->publicId())
        ->and($lead->source)->toMatchArray(['channel' => 'quote_page', 'utm_source' => 'qr', 'utm_medium' => 'qr']);

    $html = $this->get($location)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($html)->toContain('$686.000 – $839.000 COP')->toContain('Techo')->toContain('Incluye materiales.')->toContain($quote->publicId())
        ->not->toContain('ana@example.com')->not->toContain('3001234567');
    // Con la firma cambiada o de otra cotización, no existe.
    $this->get(substr($location, 0, -4).'0000')->assertNotFound();
    $this->travel(31)->days();
    $this->get($location)->assertNotFound();
});

it('con WhatsApp del negocio el resultado ofrece seguir la conversación con la referencia', function () {
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->calc->forceFill(['capture' => ['whatsapp_number' => '573001112233'] + QuoteCapture::defaults()])->save());
    $location = (string) $this->post($this->url, quotePageBody())->headers->get('Location');
    $html = $this->get($location)->assertOk()->getContent();
    expect($html)->toContain('https://wa.me/573001112233?text=')->toContain('Hablar por WhatsApp');
});

it('con errores vuelve a la página con lo escrito y el mensaje junto a la pregunta', function () {
    $html = $this->post($this->url, quotePageBody(['a' => ['area' => '1', 'tipo' => 'vinilo']]))->assertStatus(422)->getContent();
    expect($html)->toContain('Escribe un número entre 5 y 2.000.')->toContain('value="1"');
    $html = $this->post($this->url, quotePageBody(['c' => ['first_name' => 'Ana']]))->assertStatus(422)->getContent();
    expect($html)->toContain('Escribe tu WhatsApp o tu correo.')->toContain('value="Ana"');
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Quote::query()->count()))->toBe(0);
});

it('el campo trampa no guarda nada', function () {
    $this->post($this->url, quotePageBody(['website' => 'http://spam.example']))->assertStatus(303)->assertHeader('Location', rtrim((string) config('app.url'), '/').$this->url);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Quote::query()->count()))->toBe(0);
});

it('un cotizador en borrador, de otro sitio, fuera del plan o en un dominio ajeno no existe', function () {
    $draft = quoteCalculator($this->ws, ['status' => 'draft']);
    $this->get("/p/{$this->ws->publicId()}/cotizar/{$draft->publicId()}")->assertNotFound();
    [$other] = $this->connectedSite($this->ws, 'otra.co');
    $mine = quoteCalculator($this->ws, ['site_id' => $other->id]);
    $this->get("/p/{$this->site->public_key}/cotizar/{$mine->publicId()}")->assertNotFound();
    $this->get("/p/{$other->public_key}/cotizar/{$mine->publicId()}")->assertOk();
    $this->get("/p/{$this->ws->publicId()}/cotizar/{$mine->publicId()}")->assertOk();
    // Otro espacio de trabajo no abre los cotizadores de este.
    $ws2 = $this->workspaceFor($this->userWith2fa());
    $this->get("/p/{$ws2->publicId()}/cotizar/{$this->calc->publicId()}")->assertNotFound();

    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function allows(Workspace $workspace, string $module): bool
        {
            return $module !== 'quotes';
        }

        public function limit(Workspace $workspace, string $key): ?int
        {
            return null;
        }

        public function planFor(Workspace $workspace): ?string
        {
            return 'tienda_inicio';
        }
    });
    $this->get($this->url)->assertNotFound()->assertSee('Este cotizador no está disponible');
    $this->post($this->url, quotePageBody())->assertNotFound();
});

it('limita los envíos por IP', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post($this->url, quotePageBody(['a' => []]))->assertStatus(422);
    }
    $this->post($this->url, quotePageBody())->assertStatus(429);
});
