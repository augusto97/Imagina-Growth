<?php

use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->url = "/api/w/{$this->ws->publicId()}/templates";
    $this->marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
});

function templatesEmailPayload(array $overrides = []): array
{
    return $overrides + [
        'channel' => 'email', 'name' => 'Carrito abandonado', 'category' => 'marketing',
        'subject' => "{{ contacto.nombre | default: 'Hola' }}, dejaste algo",
        'preheader' => 'Lo guardamos para ti',
        'body_mjml' => '<mjml><mj-body></mj-body></mjml>',
        'body_html' => '<html><body><p>Hola {{ contacto.nombre }}</p><p>Recibes este correo porque aceptaste recibir novedades de {{ tienda.nombre }}. {{ tienda.direccion }} <a href="{{ enlaces.baja }}">Darte de baja</a></p></body></html>',
        'editor_state' => '{"pages":[]}',
    ];
}

it('marketing crea, edita, duplica y archiva; quien solo ve no puede cambiar nada', function () {
    $created = $this->actingAs($this->marketing)->postJson($this->url, templatesEmailPayload())->assertCreated();
    $id = $created->json('id');
    expect($id)->toStartWith('tpl_')->and($created->json('status'))->toBe('draft')->and($created->json('editor_state'))->toBe('{"pages":[]}');

    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['name' => 'Carrito 1', 'status' => 'ready'])->assertOk()->assertJsonPath('status', 'ready');
    $copy = $this->actingAs($this->marketing)->postJson("{$this->url}/{$id}/duplicate")->assertCreated();
    expect($copy->json('name'))->toBe('Carrito 1 (copia)')->and($copy->json('status'))->toBe('draft');

    $this->actingAs($this->viewer)->getJson($this->url)->assertOk()->assertJsonCount(2, 'data');
    $this->actingAs($this->viewer)->getJson("{$this->url}/{$id}")->assertOk()->assertJsonPath('subject', "{{ contacto.nombre | default: 'Hola' }}, dejaste algo");
    $this->actingAs($this->viewer)->postJson($this->url, templatesEmailPayload())->assertForbidden();
    $this->actingAs($this->viewer)->patchJson("{$this->url}/{$id}", ['name' => 'x'])->assertForbidden();
    $this->actingAs($this->viewer)->deleteJson("{$this->url}/{$id}")->assertForbidden();
    $this->actingAs($this->viewer)->postJson("{$this->url}/library/abandoned_cart_1")->assertForbidden();

    $this->actingAs($this->marketing)->deleteJson("{$this->url}/{$copy->json('id')}")->assertOk();
    $this->actingAs($this->marketing)->getJson($this->url)->assertJsonCount(1, 'data');
    $this->actingAs($this->marketing)->getJson("{$this->url}?status=archived")->assertJsonCount(1, 'data');
    expect(DB::table('audit_logs')->where('action', 'template.archived')->count())->toBe(1);
});

it('valida Liquid al guardar y no deja marcar lista una plantilla de marketing sin enlace de baja', function () {
    $this->actingAs($this->marketing)->postJson($this->url, templatesEmailPayload(['subject' => 'Hola {{ contacto.nombre']))
        ->assertUnprocessable()->assertJsonValidationErrors(['subject']);

    $noFooter = templatesEmailPayload(['body_html' => '<body><p>Compra ya</p></body>']);
    $id = $this->actingAs($this->marketing)->postJson($this->url, $noFooter)->assertCreated()->json('id');
    $show = $this->actingAs($this->marketing)->getJson("{$this->url}/{$id}")->assertOk();
    expect(array_column($show->json('checks'), 'code'))->toContain('unsubscribe_missing', 'address_missing', 'reason_missing');
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['status' => 'ready'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

    // Transaccional: no exige el pie de marketing.
    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['category' => 'transactional', 'status' => 'ready'])->assertOk();
    // Un SMS de marketing debe decir cómo darse de baja.
    $sms = $this->actingAs($this->marketing)->postJson($this->url, ['channel' => 'sms', 'name' => 'SMS', 'body_text' => 'Promo hoy'])->assertCreated();
    expect(array_column($sms->json('checks'), 'code'))->toContain('sms_optout_missing');
});

it('vista previa con datos de ejemplo, con un contacto real (si puede verlo) y de un borrador sin guardar', function () {
    $id = $this->actingAs($this->marketing)->postJson($this->url, templatesEmailPayload())->json('id');
    $sample = $this->actingAs($this->viewer)->postJson("{$this->url}/{$id}/preview")->assertOk();
    expect($sample->json('subject'))->toBe('Laura, dejaste algo')->and($sample->json('html'))->toContain('Hola Laura')->and($sample->json('text'))->toContain('Hola Laura');

    $contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Marta', 'email' => 'marta@ejemplo.com']));
    $real = $this->actingAs($this->marketing)->postJson("{$this->url}/{$id}/preview", ['contact_id' => $contact->publicId()])->assertOk();
    expect($real->json('subject'))->toBe('Marta, dejaste algo')->and($real->json('html'))->not->toContain('marta@ejemplo.com')
        ->and($real->json('contact.id'))->toBe($contact->publicId());
    $this->actingAs($this->viewer)->postJson("{$this->url}/{$id}/preview", ['contact_id' => $contact->publicId()])->assertForbidden();

    $draft = $this->actingAs($this->marketing)->postJson("{$this->url}/preview", ['channel' => 'sms', 'body_text' => 'Hola {{ contacto.nombre }}, tu código {{ cupon.codigo }}'])->assertOk();
    expect($draft->json('text'))->toBe('Hola Laura, tu código VUELVE-7K2Q9XAB')->and($draft->json('sms.encoding'))->toBe('UCS-2');
    $this->actingAs($this->marketing)->postJson("{$this->url}/preview", ['channel' => 'email', 'subject' => 'x', 'body_html' => '<body>{{ x | inventado }}</body>'])
        ->assertStatus(422)->assertJsonPath('ok', false);
});

it('el catálogo de variables, las plantillas de WhatsApp sincronizadas y su mapeo', function () {
    $groups = $this->actingAs($this->viewer)->getJson("{$this->url}/variables")->assertOk()->json('groups');
    expect(array_column($groups, 'key'))->toContain('contacto', 'carrito', 'cupon', 'enlaces');

    $waId = strtoupper((string) Str::ulid());
    DB::table('wa_templates')->insert(['id' => $waId, 'workspace_id' => $this->ws->id, 'channel_account_id' => strtoupper((string) Str::ulid()), 'name' => 'carrito_1', 'language' => 'es_CO', 'category' => 'MARKETING', 'status' => 'APPROVED',
        'components' => json_encode([['type' => 'BODY', 'text' => 'Hola {{1}}, te espera {{2}}']]), 'created_at' => now(), 'updated_at' => now()]);
    $sources = $this->actingAs($this->marketing)->getJson("{$this->url}/wa-sources")->assertOk();
    expect($sources->json('data.0.slots.body'))->toBe(['1', '2']);

    $created = $this->actingAs($this->marketing)->postJson($this->url, ['channel' => 'whatsapp', 'name' => 'Carrito WA', 'wa_template_id' => $waId, 'variables' => ['body' => ['1' => '{{ contacto.nombre }}']]])->assertCreated();
    expect(array_column($created->json('checks'), 'code'))->toContain('wa_variables_missing');
    $preview = $this->actingAs($this->marketing)->patchJson("{$this->url}/{$created->json('id')}", ['variables' => ['body' => ['1' => '{{ contacto.nombre }}', '2' => '{{ carrito.subtotal | moneda }}']], 'status' => 'ready'])->assertOk();
    expect($preview->json('checks'))->toBe([]);
    $rendered = $this->actingAs($this->marketing)->postJson("{$this->url}/{$created->json('id')}/preview")->assertOk();
    expect($rendered->json('whatsapp.components.0.parameters.1.text'))->toBe('$ 320.000');

    $other = $this->workspaceFor($this->owner, 'Otra');
    $foreignWa = strtoupper((string) Str::ulid());
    DB::table('wa_templates')->insert(['id' => $foreignWa, 'workspace_id' => $other->id, 'channel_account_id' => strtoupper((string) Str::ulid()), 'name' => 'x', 'language' => 'es', 'category' => 'MARKETING', 'status' => 'APPROVED', 'components' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->marketing)->postJson($this->url, ['channel' => 'whatsapp', 'name' => 'Ajena', 'wa_template_id' => $foreignWa])->assertUnprocessable()->assertJsonValidationErrors(['wa_template_id']);
});

it('datos del negocio para el pie legal: solo dueños y administradores los cambian', function () {
    $this->actingAs($this->marketing)->putJson("{$this->url}/business-profile", ['address' => 'Calle 1'])->assertForbidden();
    $this->actingAs($this->owner)->putJson("{$this->url}/business-profile", ['name' => 'Pinturas La 14', 'address' => 'Calle 23 # 21-40', 'whatsapp' => '+57 300 123 4567'])
        ->assertOk()->assertJsonPath('address', 'Calle 23 # 21-40');
    $this->actingAs($this->owner)->putJson("{$this->url}/business-profile", ['logo_url' => 'http://inseguro.co/logo.png'])->assertUnprocessable();
    $html = $this->actingAs($this->viewer)->postJson("{$this->url}/preview", ['channel' => 'email', 'subject' => 'x', 'body_html' => '<body>{{ tienda.direccion }} {{ tienda.whatsapp_url }}</body>'])->json('html');
    expect($html)->toContain('Calle 23 # 21-40 https://wa.me/573001234567');
});

it('no se ve ni se toca una plantilla de otro espacio de trabajo', function () {
    $id = $this->actingAs($this->owner)->postJson($this->url, templatesEmailPayload())->json('id');
    $intruder = $this->userWith2fa();
    $theirs = $this->workspaceFor($intruder, 'Ajena');
    $their = "/api/w/{$theirs->publicId()}/templates";
    $this->actingAs($intruder)->getJson("{$their}/{$id}")->assertNotFound();
    $this->actingAs($intruder)->patchJson("{$their}/{$id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($intruder)->postJson("{$their}/{$id}/preview")->assertNotFound();
    $this->actingAs($intruder)->getJson($their)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($intruder)->getJson("{$this->url}/{$id}")->assertNotFound();
    expect(app(CurrentWorkspace::class)->run($theirs, fn () => Template::query()->count()))->toBe(0);
});

it('envía una prueba del correo del editor con datos de ejemplo y dice qué falta si no puede', function () {
    $fake = FakeMessageDispatcher::install();
    $body = '<html><body><p>Hola {{ contacto.nombre }}, {% if tienda.nombre != "" %}de {{ tienda.nombre }}{% endif %}</p></body></html>';
    $res = $this->actingAs($this->marketing)->postJson("{$this->url}/test-send", ['subject' => 'Tu cita, {{ contacto.nombre }}', 'category' => 'marketing', 'body_html' => $body, 'to' => 'prueba@eltornillo.co'])
        ->assertOk()->assertJsonPath('sent', true)->assertJsonPath('to', 'prueba@eltornillo.co');
    expect($res->json('message'))->toContain('spam');
    $message = $fake->dispatched[0];
    expect($message->isTest)->toBeTrue()
        ->and($message->to)->toBe('prueba@eltornillo.co')
        ->and($message->kind)->toBe(MessageKind::Transactional)
        ->and($message->channel)->toBe(Channel::Email)
        ->and($message->contactId)->toBeNull()
        ->and($message->context['subject'])->toStartWith('[Prueba] Tu cita, ')
        ->and($message->context['html'])->not->toContain('{{')->not->toContain('{%');

    // Sin canal de correo: lo dice en palabras, sin error.
    $fake->respondWith(fn () => DispatchResult::denied(PolicyDecision::CHANNEL_UNAVAILABLE));
    $this->actingAs($this->marketing)->postJson("{$this->url}/test-send", ['subject' => 'x', 'body_html' => $body, 'to' => 'prueba@eltornillo.co'])
        ->assertOk()->assertJsonPath('sent', false)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Conexiones'));
    // Una dirección que no es correo y un rol que no puede enviar.
    $this->actingAs($this->marketing)->postJson("{$this->url}/test-send", ['body_html' => $body, 'to' => 'no-es-correo'])->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->actingAs($this->viewer)->postJson("{$this->url}/test-send", ['body_html' => $body, 'to' => 'prueba@eltornillo.co'])->assertForbidden();
    expect($fake->dispatched)->toHaveCount(1);
});

it('avisa cuando quedan imágenes de muestra de los bloques del editor', function () {
    $payload = templatesEmailPayload(['category' => 'utility', 'body_html' => '<html><body><img src="https://growth.test/images/email/placeholder-hero.png" alt=""><p>Hola</p></body></html>']);
    $id = $this->actingAs($this->marketing)->postJson($this->url, $payload)->assertCreated()->json('id');
    $checks = collect($this->actingAs($this->marketing)->getJson("{$this->url}/{$id}")->json('checks'))->pluck('code');
    expect($checks)->toContain('placeholder_image');

    $this->actingAs($this->marketing)->patchJson("{$this->url}/{$id}", ['body_html' => '<html><body><img src="https://tienda.co/foto.png" alt=""><p>Hola</p></body></html>'])->assertOk();
    $checks = collect($this->actingAs($this->marketing)->getJson("{$this->url}/{$id}")->json('checks'))->pluck('code');
    expect($checks)->not->toContain('placeholder_image');
});
