<?php

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\WidgetTemplates;

// Galería de plantillas de «Popups y formularios» (modulos.md M12): un widget listo para cada objetivo, en un clic.
beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->base = "/api/w/{$this->ws->publicId()}/onsite";
});

it('ofrece las plantillas por objetivo y tipo de negocio en las opciones del constructor', function () {
    $templates = collect($this->actingAs($this->owner)->getJson("{$this->base}/options")->assertOk()->json('templates'))->keyBy('key');

    expect($templates->keys()->all())->toEqual(array_keys(WidgetTemplates::ALL))
        ->and($templates['contact_form'])->toMatchArray(['kind' => 'embedded_form', 'objective' => 'contact', 'audience' => 'services', 'gives_coupon' => false])
        ->and($templates['whatsapp_coupon'])->toMatchArray(['kind' => 'popup', 'audience' => 'store', 'gives_coupon' => true]);
    // Un negocio sin tienda nunca recibe una plantilla con cupón (lo crea WooCommerce).
    expect($templates->where('audience', 'services')->where('gives_coupon', true))->toBeEmpty();
});

it('cada plantilla crea un borrador válido que se puede prender', function () {
    $this->ws->forceFill(['settings' => array_merge((array) $this->ws->settings, ['business_profile' => ['whatsapp' => '300 123 4567']])])->save();
    foreach (array_keys(WidgetTemplates::ALL) as $key) {
        $w = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => "Plantilla {$key}", 'template' => $key])->assertCreated()->json();
        expect($w['kind'])->toBe(WidgetTemplates::kind($key))->and($w['status'])->toBe('draft')->and($w['name'])->toBe("Plantilla {$key}");
        $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/activate")->assertOk()->assertJsonPath('status', 'active');
    }
});

it('la salida se muestra al intentar irse, una vez cada dos semanas; el formulario de contacto no regala cupón', function () {
    $exit = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'Antes de irte', 'template' => 'exit_info'])->assertCreated();
    expect($exit->json('targeting.trigger.type'))->toBe('exit_intent')
        ->and($exit->json('targeting.frequency'))->toBe(['max_views' => 1, 'cooldown_days' => 14])
        ->and($exit->json('coupon_config.enabled'))->toBeFalse()
        ->and(array_column($exit->json('variants.0.content.fields'), 'key'))->toBe(['first_name', 'phone']);

    $contact = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'Contacto', 'template' => 'contact_form'])->assertCreated();
    expect($contact->json('consent_config.channels'))->toBe(['whatsapp', 'email'])->and($contact->json('coupon_config.enabled'))->toBeFalse();

    $survey = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'Encuesta', 'template' => 'survey'])->assertCreated();
    expect($survey->json('quiz.questions.0.attribute'))->toBe('encuesta_motivo')->and($survey->json('variants.0.content.contact_optional'))->toBeTrue();
});

it('el botón de WhatsApp toma el número del negocio y una plantilla desconocida se rechaza', function () {
    $this->ws->forceFill(['settings' => array_merge((array) $this->ws->settings, ['business_profile' => ['whatsapp' => '300 765 4321']])])->save();
    $wa = $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'WhatsApp', 'template' => 'wa_button'])->assertCreated();
    expect($wa->json('variants.0.content.wa.number'))->toBe('573007654321');

    $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'X', 'template' => 'inventada'])->assertUnprocessable()->assertJsonValidationErrors('template');
    $this->actingAs($this->owner)->postJson("{$this->base}/widgets", ['name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('kind');
});

it('un widget guardado a medias llega completo al constructor (los valores de su tipo llenan lo que falta)', function () {
    $widget = OnsiteWidget::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Viejo', 'kind' => 'embedded_form', 'status' => 'active',
        'content' => ['title' => 'Escríbenos'], 'consent_config' => ['channels' => ['email']]]);
    $res = $this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$widget->publicId()}")->assertOk();
    expect($res->json('targeting'))->toHaveKeys(['trigger', 'pages', 'page_types', 'devices', 'frequency'])
        ->and($res->json('variants.0.content.title'))->toBe('Escríbenos')
        ->and($res->json('variants.0.content.fields'))->not->toBeEmpty()
        ->and($res->json('variants.0.design.theme'))->toHaveKeys(['background', 'text', 'accent', 'accent_text'])
        ->and($res->json('consent_config'))->toBe(['channels' => ['email'], 'double_opt_in' => false, 'template_id' => null]);
});
