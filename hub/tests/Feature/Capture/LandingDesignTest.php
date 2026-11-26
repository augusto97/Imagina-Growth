<?php

use App\Domain\Capture\Models\LandingPage;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Reviews\Models\Review;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/CaptureFixtures.php';

/*
 * Páginas de captura 0.3 (modulos.md M12): el asistente (objetivo y diseño), los tres diseños, la etiqueta, los
 * beneficios, las preguntas frecuentes, las reseñas como prueba social y la vista previa del editor, que pinta el
 * borrador sin guardarlo con la misma vista que ve el cliente.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
    $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota', 'privacy_policy_url' => 'https://sonrisa.co/privacidad'])->save();
    $this->api = "/api/w/{$this->ws->publicId()}";
    FakeMessageDispatcher::install();
});

it('el asistente crea la página con los textos, las preguntas y los campos del objetivo y el diseño elegido', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Cotización de pintura', 'goal' => 'quote', 'template' => 'hero'])->assertCreated();
    expect($res->json('content'))->toMatchArray(['template' => 'hero', 'eyebrow' => 'Cotización sin costo', 'title' => 'Recibe tu cotización sin costo', 'button' => 'Quiero mi cotización', 'reviews' => false])
        ->and($res->json('content.benefits'))->toHaveCount(3)
        ->and($res->json('content.faq.0.q'))->toBe('¿La cotización tiene costo?')
        ->and(array_column($res->json('fields'), 'key'))->toBe(['name', 'phone', 'email', 'custom_que_necesitas', 'message']);

    // Sin diseño, el que mejor le va al objetivo; un objetivo o un diseño desconocidos no pasan.
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Promo', 'goal' => 'offer'])->assertCreated()->assertJsonPath('content.template', 'hero');
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'X', 'goal' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('goal');
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'X', 'template' => 'grid'])->assertUnprocessable()->assertJsonValidationErrors('template');
    // Sin objetivo, la de siempre (formulario de contacto, diseño clásico, sin beneficios ni preguntas).
    $plain = $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Contacto'])->assertCreated();
    expect($plain->json('content'))->toMatchArray(['template' => 'split', 'benefits' => [], 'faq' => [], 'reviews' => false]);
});

it('guarda la etiqueta, los beneficios y las preguntas sin renglones vacíos y con sus límites', function () {
    $id = (string) $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Clínica Sonrisa'])->json('id');
    $res = $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['content' => [
        'template' => 'centered', 'eyebrow' => ' Valoración gratis ', 'reviews' => true,
        'benefits' => ['Te atiende un especialista', '  ', '<b>Sin costo</b>'],
        'faq' => [['q' => '¿Duele?', 'a' => 'No.'], ['q' => ' ', 'a' => 'sin pregunta'], ['q' => '¿Hay parqueadero?']],
    ]])->assertOk();
    expect($res->json('content'))->toMatchArray(['template' => 'centered', 'eyebrow' => 'Valoración gratis', 'reviews' => true,
        'benefits' => ['Te atiende un especialista', 'Sin costo'],
        'faq' => [['q' => '¿Duele?', 'a' => 'No.'], ['q' => '¿Hay parqueadero?', 'a' => '']]]);

    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['content' => ['benefits' => array_fill(0, 7, 'Uno')]])
        ->assertUnprocessable()->assertJsonValidationErrors('content.benefits');
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['content' => ['template' => 'grid']])
        ->assertUnprocessable()->assertJsonValidationErrors('content.template');
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['content' => ['eyebrow' => str_repeat('a', 61)]])
        ->assertUnprocessable()->assertJsonValidationErrors('content.eyebrow');
});

it('una página de antes abre con las claves nuevas en su valor por defecto', function () {
    $id = (string) $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Vieja'])->json('id');
    captureIn($this->ws, function () use ($id) {
        $page = LandingPage::findByPublicId($id);
        $content = (array) $page->content;
        unset($content['template'], $content['eyebrow'], $content['benefits'], $content['faq'], $content['reviews']);
        $page->forceFill(['content' => $content])->save();
    });
    $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages/{$id}")->assertOk()
        ->assertJsonPath('content.template', 'split')->assertJsonPath('content.benefits', [])->assertJsonPath('content.reviews', false);
});

it('la página pública pinta el diseño, la etiqueta, los beneficios, las preguntas y las reseñas publicadas', function () {
    [$site] = $this->connectedSite($this->ws);
    captureIn($this->ws, function () use ($site) {
        foreach ([[5, 'Me atendieron el mismo día.', 'Carolina R.', 'published'], [4, 'Muy claros con los precios.', 'Andrés M.', 'published'],
            [2, 'No me gustó.', 'Pedro P.', 'published'], [5, 'Pendiente de moderar.', 'Ana', 'pending']] as $i => [$rating, $body, $author, $status]) {
            Review::query()->create(['site_id' => $site->id, 'rating' => $rating, 'body' => $body, 'author_name' => $author, 'status' => $status,
                'source' => 'manual', 'verified_buyer' => true, 'published_at' => $status === 'published' ? now()->subDays($i + 1) : null]);
        }
    });
    $page = capturePublishedPage($this, ['content' => [
        'template' => 'hero', 'eyebrow' => 'Valoración gratis', 'reviews' => true,
        'benefits' => ['Te atiende un especialista', 'Sin costo'],
        'faq' => [['q' => '¿Tiene costo?', 'a' => 'No, la valoración es sin costo.']],
    ]]);
    $html = (string) $this->get("/p/{$page->slug}")->assertOk()->getContent();
    expect($html)->toContain('class="t-hero')->toContain('class="eyebrow">Valoración gratis')
        ->toContain('Te atiende un especialista')->toContain('<summary>¿Tiene costo?</summary>')
        ->toContain('Lo que dicen nuestros clientes')->toContain('Me atendieron el mismo día.')->toContain('Carolina R.')
        // Promedio de las publicadas (5, 4 y 2) y solo las de 4 o 5 estrellas con texto.
        ->toContain('3,7')->not->toContain('No me gustó.')->not->toContain('Pendiente de moderar.')
        ->toContain('id="sticky-cta"')->toContain('id="formulario"');

    // Sin reseñas que mostrar, la página no inventa una prueba social.
    $quiet = capturePublishedPage($this, ['content' => ['reviews' => false]], 'Otra página');
    expect((string) $this->get("/p/{$quiet->slug}")->assertOk()->getContent())->toContain('class="t-split')->not->toContain('reseñas')->not->toContain('id="sticky-cta"');
});

it('la vista previa del editor pinta el borrador sin guardarlo, sin scripts y sin enviar nada', function () {
    $id = (string) $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Clínica Sonrisa'])->json('id');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages/{$id}/preview", [
        'content' => ['title' => 'Título sin guardar', 'template' => 'centered', 'benefits' => ['Beneficio nuevo']],
    ])->assertOk();
    $html = (string) $res->json('html');
    expect($html)->toContain('Título sin guardar')->toContain('class="t-centered')->toContain('Beneficio nuevo')
        ->toContain('Vista previa: así se verá la página')->toContain('disabled')->not->toContain('<script')->not->toContain('name="t" value="ey');
    // No se guardó nada.
    $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages/{$id}")->assertJsonPath('content.title', 'Te ayudamos hoy mismo')->assertJsonPath('content.template', 'split');

    $thanks = (string) $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages/{$id}/preview", ['content' => ['success_title' => '¡Listo, te escribimos!'], 'state' => 'thanks'])
        ->assertOk()->json('html');
    expect($thanks)->toContain('¡Listo, te escribimos!');

    // Un borrador inválido no se pinta (el editor se queda con la última vista válida).
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages/{$id}/preview", ['fields' => [['key' => 'name', 'label' => 'Nombre']]])
        ->assertUnprocessable()->assertJsonValidationErrors('fields');
    // De otro espacio de trabajo no se ve.
    $other = $this->userWith2fa();
    $this->workspaceFor($other, 'Otro negocio');
    $this->actingAs($other)->postJson("{$this->api}/capture/pages/{$id}/preview", [])->assertNotFound();
});

it('las opciones del editor dicen si hay reseñas para mostrar, los diseños y los objetivos', function () {
    $this->actingAs($this->owner)->getJson("{$this->api}/capture/options")->assertOk()
        ->assertJsonPath('reviews', null)->assertJsonPath('templates', ['split', 'hero', 'centered'])
        ->assertJsonPath('goals', ['contact', 'quote', 'appointment', 'offer', 'event']);
});
