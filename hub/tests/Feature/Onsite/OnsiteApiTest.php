<?php

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Models\WidgetCapture;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

// API de «En el sitio» (M12) para la SPA: constructor, estados, permisos y aislamiento.
beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->base = "/api/w/{$this->ws->publicId()}/onsite";
});

function onsiteCreate($test, string $kind = 'popup', string $name = 'Popup de bienvenida'): array
{
    return $test->actingAs($test->owner)->postJson("{$test->base}/widgets", ['name' => $name, 'kind' => $kind])->assertCreated()->json();
}

it('crea cada tipo de widget con valores por defecto listos para activar', function () {
    foreach (OnsiteWidget::KINDS as $kind) {
        $w = onsiteCreate($this, $kind, "Widget {$kind}");
        expect($w['id'])->toStartWith('wg_')->and($w['status'])->toBe('draft')->and($w['kind'])->toBe($kind)
            ->and($w['variants'])->toHaveCount(1)->and($w['targeting']['frequency'])->toHaveKeys(['max_views', 'cooldown_days']);
        if ($kind === 'wa_widget') {
            // Sin número de WhatsApp no se activa.
            $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/activate")->assertUnprocessable()->assertJsonValidationErrors('wa.number');

            continue;
        }
        $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/activate")->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('live', true);
    }
    $quiz = collect($this->actingAs($this->owner)->getJson("{$this->base}/widgets?status=active")->json('data'))->firstWhere('kind', 'quiz');
    $detail = $this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$quiz['id']}")->assertOk();
    expect($detail->json('quiz.questions'))->toHaveCount(2)->and($detail->json('quiz.outcomes.limit'))->toBe(4);
});

it('guarda la configuración normalizada: variantes A/B, segmentación, consentimiento, cupón y programación', function () {
    $w = onsiteCreate($this);
    $res = $this->actingAs($this->owner)->patchJson("{$this->base}/widgets/{$w['id']}", [
        'name' => 'Popup 10%',
        'site_id' => $this->site->publicId(),
        'priority' => 5,
        'targeting' => [
            'trigger' => ['type' => 'scroll', 'value' => 140], 'pages' => ['include' => ['tienda/*', 'https://tienda.co/ofertas'], 'exclude' => ['/checkout*']],
            'page_types' => ['home', 'product', 'checkout', 'inventado'], 'devices' => ['mobile', 'tv'], 'utm' => ['source' => ' instagram ', 'medium' => ''],
            'countries' => ['co', 'MEX', 'mx'], 'cart_value_min' => '100000', 'new_visitors' => true, 'frequency' => ['max_views' => 3, 'cooldown_days' => 14],
            'hide_for_subscribers' => false, 'campo_raro' => 'x',
        ],
        'variants' => [
            ['weight' => 50, 'design' => ['position' => 'corner', 'theme' => ['accent' => '#ff0000', 'text' => 'rojo']], 'content' => [
                'title' => '<b>10%</b> para ti', 'cta' => 'Lo quiero', 'success' => 'Tu cupón: {{codigo}}',
                'fields' => [['key' => 'phone', 'required' => true, 'label' => 'WhatsApp'], ['key' => 'phone'], ['key' => 'first_name']],
            ]],
            ['weight' => 50, 'design' => ['position' => 'fullscreen'], 'content' => ['title' => 'Variante B', 'cta' => 'Quiero', 'fields' => [['key' => 'email', 'required' => true]]]],
        ],
        'consent_config' => ['channels' => ['whatsapp', 'email', 'fax'], 'double_opt_in' => true],
        'coupon_config' => ['enabled' => true, 'kind' => 'percent', 'amount' => 15, 'expires_in_days' => 5, 'prefix' => 'hola-10', 'minimum_amount' => '80000'],
        'starts_at' => '2026-11-01T00:00:00-05:00',
        'ab_auto_winner' => true,
    ])->assertOk();

    expect($res->json('name'))->toBe('Popup 10%')->and($res->json('site_id'))->toBe($this->site->publicId())->and($res->json('priority'))->toBe(5)
        ->and($res->json('targeting.trigger'))->toBe(['type' => 'scroll', 'value' => 100])
        ->and($res->json('targeting.pages'))->toBe(['include' => ['/tienda/*', '/ofertas'], 'exclude' => ['/checkout*']])
        ->and($res->json('targeting.page_types'))->toBe(['home', 'product'])        // nunca checkout
        ->and($res->json('targeting.devices'))->toBe(['mobile'])
        ->and($res->json('targeting.utm'))->toBe(['source' => 'instagram'])
        ->and($res->json('targeting.countries'))->toBe(['CO', 'MX'])
        ->and($res->json('targeting.cart_value_min'))->toBe('100000.00')
        ->and($res->json('targeting'))->not->toHaveKey('campo_raro')
        ->and($res->json('variants.0.key'))->toBe('a')->and($res->json('variants.1.key'))->toBe('b')
        ->and($res->json('variants.0.design.position'))->toBe('corner')
        ->and($res->json('variants.0.design.theme.accent'))->toBe('#FF0000')->and($res->json('variants.0.design.theme.text'))->toBe('#111827')
        ->and($res->json('variants.0.content.title'))->toBe('10% para ti')
        ->and(array_column($res->json('variants.0.content.fields'), 'key'))->toBe(['phone', 'first_name'])
        ->and($res->json('variants.0.content.fields.0.type'))->toBe('tel')
        ->and($res->json('consent_config'))->toBe(['channels' => ['email', 'whatsapp'], 'double_opt_in' => true, 'template_id' => null])
        ->and($res->json('coupon_config'))->toMatchArray(['enabled' => true, 'amount' => '15.00', 'prefix' => 'HOLA10', 'minimum_amount' => '80000.00', 'expires_in_days' => 5])
        ->and($res->json('starts_at'))->toBe('2026-11-01T05:00:00+00:00')
        ->and($res->json('ab.auto_winner'))->toBeTrue()
        ->and($res->json('variants_count'))->toBe(2);
});

it('rechaza configuraciones inválidas con mensajes en español', function () {
    $w = onsiteCreate($this);
    $bad = $this->actingAs($this->owner)->patchJson("{$this->base}/widgets/{$w['id']}", [
        'name' => '',
        'variants' => [['content' => ['fields' => [['key' => 'cedula']]]]],
        'coupon_config' => ['enabled' => true, 'kind' => 'percent', 'amount' => 150],
        'starts_at' => '2026-12-10', 'ends_at' => '2026-12-01',
        'targeting' => ['segments' => ['sg_01JDZZZZZZZZZZZZZZZZZZZZZZ']],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'variants.a.fields', 'coupon_config.amount', 'ends_at', 'targeting.segments']);
    expect($bad->json('errors')['coupon_config.amount'][0])->toBe('El porcentaje debe estar entre 1 y 100.');

    // Un widget activo no puede quedar sin correo ni WhatsApp.
    $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/activate")->assertOk();
    $this->actingAs($this->owner)->patchJson("{$this->base}/widgets/{$w['id']}", ['variants' => [['content' => ['title' => 'Hola', 'cta' => 'Ok', 'fields' => [['key' => 'first_name']]]]]])
        ->assertUnprocessable()->assertJsonValidationErrors('fields');
    expect($this->actingAs($this->owner)->getJson("{$this->base}/widgets/{$w['id']}")->json('variants.0.content.fields.1.key'))->toBe('phone');

    // Quiz: la lógica no puede saltar a una pregunta que no existe.
    $q = onsiteCreate($this, 'quiz', 'Quiz');
    $this->actingAs($this->owner)->patchJson("{$this->base}/widgets/{$q['id']}", ['quiz' => ['questions' => [
        ['id' => 'q1', 'text' => '¿Piel?', 'options' => [['key' => 'seca', 'label' => 'Seca', 'next' => 'q9'], ['key' => 'grasa', 'label' => 'Grasa']]],
    ]]])->assertUnprocessable()->assertJsonValidationErrors('quiz.questions.0');
});

it('pausa, archiva, duplica y borra solo lo que no capturó contactos, con auditoría', function () {
    $w = onsiteCreate($this);
    $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/activate")->assertOk();
    $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/pause")->assertOk()->assertJsonPath('status', 'paused')->assertJsonPath('live', false);
    $copy = $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/duplicate")->assertCreated();
    expect($copy->json('name'))->toBe('Popup de bienvenida (copia)')->and($copy->json('status'))->toBe('draft')->and($copy->json('id'))->not->toBe($w['id']);

    // Con capturas no se borra: se archiva.
    app(CurrentWorkspace::class)->run($this->ws, fn () => WidgetCapture::query()->create([
        'widget_id' => substr($w['id'], 3), 'contact_id' => '01JDZZZZZZZZZZZZZZZZZZZZZZ', 'variant' => 'a', 'captured_at' => now(),
    ]));
    $this->actingAs($this->owner)->deleteJson("{$this->base}/widgets/{$w['id']}")->assertStatus(409);
    $this->actingAs($this->owner)->postJson("{$this->base}/widgets/{$w['id']}/archive")->assertOk()->assertJsonPath('status', 'archived');
    $this->actingAs($this->owner)->patchJson("{$this->base}/widgets/{$w['id']}", ['name' => 'Otra'])->assertUnprocessable();
    $this->actingAs($this->owner)->deleteJson("{$this->base}/widgets/{$copy->json('id')}")->assertOk();

    expect(collect($this->actingAs($this->owner)->getJson("{$this->base}/widgets")->json('data'))->pluck('id')->all())->toBe([])
        ->and($this->actingAs($this->owner)->getJson("{$this->base}/widgets?status=archived")->json('data.0.id'))->toBe($w['id']);
    $actions = DB::table('audit_logs')->where('workspace_id', $this->ws->id)->where('action', 'like', 'onsite_widget.%')->pluck('action')->all();
    expect($actions)->toContain('onsite_widget.created', 'onsite_widget.activated', 'onsite_widget.paused', 'onsite_widget.archived', 'onsite_widget.deleted');
});

it('marketing edita, viewer solo mira, agent no entra y otro espacio de trabajo no ve nada', function () {
    $w = onsiteCreate($this);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);

    $this->actingAs($marketing)->patchJson("{$this->base}/widgets/{$w['id']}", ['name' => 'Editado por marketing'])->assertOk();
    $this->actingAs($viewer)->getJson("{$this->base}/widgets")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($viewer)->getJson("{$this->base}/widgets/{$w['id']}/stats")->assertOk();
    $this->actingAs($viewer)->getJson("{$this->base}/widgets/{$w['id']}/captures")->assertOk()->assertJsonPath('data', []);
    $this->actingAs($viewer)->patchJson("{$this->base}/widgets/{$w['id']}", ['name' => 'No'])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$this->base}/widgets", ['name' => 'No', 'kind' => 'popup'])->assertForbidden();
    $this->actingAs($agent)->getJson("{$this->base}/widgets")->assertForbidden();

    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra tienda');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/onsite/widgets/{$w['id']}")->assertNotFound();
    $this->actingAs($other)->postJson("/api/w/{$otherWs->publicId()}/onsite/widgets/{$w['id']}/activate")->assertNotFound();
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/onsite/widgets")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->getJson("{$this->base}/widgets")->assertNotFound();
});

it('ofrece al constructor los sitios, segmentos, textos legales, plantillas de correo y categorías', function () {
    $opts = $this->actingAs($this->owner)->getJson("{$this->base}/options")->assertOk();
    expect($opts->json('kinds'))->toBe(OnsiteWidget::KINDS)
        ->and($opts->json('sites.0.id'))->toBe($this->site->publicId())
        ->and(collect($opts->json('consent_texts'))->pluck('channel')->all())->toBe(['whatsapp', 'email'])
        ->and($opts->json('page_types'))->not->toContain('checkout')
        ->and($opts->json('workspace.currency'))->toBe('COP');
});
