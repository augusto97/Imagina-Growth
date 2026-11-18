<?php

use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Graph\GraphValidator;
use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Support\FlowSecrets;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Flows\Support\FlowFixtures;

uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
    $this->fakeCoupons();
});

function flowGraphErrors($test, array $graph): array
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(GraphValidator::class)->validate(Graph::fromArray($graph), $test->ws->id));
}

it('valida el grafo: disparador único, todo conectado, ciclos con espera, ramas, plantillas y fases', function () {
    $t = ['id' => 'trigger', 'type' => 'trigger', 'config' => []];
    $send = ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'no_existe']];
    $errors = flowGraphErrors($this, ['nodes' => [$t, $send, ['id' => 'x', 'type' => 'tag', 'config' => ['add' => ['vip']]], ['id' => 'p', 'type' => 'give_points', 'config' => []]], 'edges' => [['from' => 'trigger', 'to' => 'wa'], ['from' => 'wa', 'to' => 'p', 'branch' => 'quizas']]]);
    expect($errors['wa'])->toContain('Elige una plantilla de WhatsApp para este envío.')
        ->and(implode(' ', $errors['wa']))->toContain('Rama no válida')
        ->and($errors['x'])->toContain('Este paso no está conectado al flujo.')
        ->and($errors['p'])->toContain('Este paso llega en la Fase 3.');

    // Un ciclo sin espera no se puede publicar; con una espera, sí.
    $loop = fn (string $type, array $config) => ['nodes' => [$t, ['id' => 'a', 'type' => 'tag', 'config' => ['add' => ['x']]], ['id' => 'b', 'type' => $type, 'config' => $config]],
        'edges' => [['from' => 'trigger', 'to' => 'a'], ['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'a']]];
    expect(implode(' ', array_merge(...array_values(flowGraphErrors($this, $loop('tag', ['add' => ['y']]))))))->toContain('ciclo no tiene ninguna espera')
        ->and(flowGraphErrors($this, $loop('wait', ['duration' => ['value' => 1, 'unit' => 'days']])))->toBe([]);

    // Reclasificar un envío como transaccional exige justificarlo.
    $util = ['nodes' => [$t, ['id' => 'u', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_cod_confirm', 'kind' => 'transactional']]], 'edges' => [['from' => 'trigger', 'to' => 'u']]];
    expect(implode(' ', flowGraphErrors($this, $util)['u']))->toContain('por qué este mensaje no es de marketing');
});

it('todos los flujos listos de la biblioteca son válidos con las plantillas de M08', function () {
    $available = array_filter(FlowLibrary::all(), fn ($f) => $f['graph'] !== null);
    expect($available)->toHaveCount(11)->and(FlowLibrary::all())->toHaveCount(20);
    foreach ($available as $flow) {
        expect(flowGraphErrors($this, $flow['graph']))->toBe([], $flow['key']);
    }
});

it('la API crea, edita, publica, activa, pausa y archiva; los errores llegan por nodo', function () {
    $owner = $this->owner;
    $created = $this->actingAs($owner)->postJson($this->api(), ['name' => 'Reactivar compradores'])->assertCreated();
    $id = $created->json('id');
    expect($created->json('status'))->toBe('draft')->and($created->json('graph.nodes.0.type'))->toBe('trigger');

    $graph = ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => []]], 'edges' => [['from' => 'trigger', 'to' => 'wa']]];
    $saved = $this->actingAs($owner)->patchJson($this->api("/{$id}"), ['graph' => $graph, 'trigger' => ['type' => 'event', 'event' => 'order.paid', 'filters' => [['prop' => 'order.totals.total', 'operator' => 'gte', 'value' => '100000']]], 'goal' => ['event' => 'order.paid'], 'holdout_pct' => 10])->assertOk();
    expect($saved->json('issues.wa.0'))->toContain('plantilla')->and($saved->json('trigger.filters.0.operator'))->toBe('gte');
    $this->actingAs($owner)->postJson($this->api("/{$id}/publish"))->assertUnprocessable()->assertJsonValidationErrors(['graph.nodes.wa']);
    $this->actingAs($owner)->patchJson($this->api("/{$id}"), ['trigger' => ['type' => 'event', 'event' => 'no.existe']])->assertUnprocessable()->assertJsonValidationErrors(['trigger.event']);
    $this->actingAs($owner)->patchJson($this->api("/{$id}"), ['holdout_pct' => 80])->assertUnprocessable();

    $graph['nodes'][1]['config'] = ['template_key' => 'wa_birthday'];
    $this->actingAs($owner)->patchJson($this->api("/{$id}"), ['graph' => $graph])->assertOk()->assertJsonPath('issues', []);
    $this->actingAs($owner)->postJson($this->api("/{$id}/activate"))->assertUnprocessable(); // sin publicar
    $published = $this->actingAs($owner)->postJson($this->api("/{$id}/publish"))->assertOk();
    expect($published->json('current_version.version'))->toBe(1)->and($published->json('has_draft'))->toBeFalse()
        ->and($published->json('graph.nodes.1.config.template_id'))->toStartWith('tpl_');
    $this->actingAs($owner)->postJson($this->api("/{$id}/activate"))->assertOk()->assertJsonPath('status', 'active');
    $this->actingAs($owner)->postJson($this->api("/{$id}/pause"))->assertOk()->assertJsonPath('status', 'paused');

    $list = $this->actingAs($owner)->getJson($this->api())->assertOk();
    expect($list->json('data.0.id'))->toBe($id);
    $this->actingAs($owner)->getJson($this->api("/{$id}/analytics"))->assertOk()->assertJsonPath('summary.entered', 0)->assertJsonPath('uplift.reason', 'no_holdout');
    $this->actingAs($owner)->getJson($this->api('/options'))->assertOk()->assertJsonFragment(['type' => 'send_whatsapp']);

    // Con versión publicada no se borra: se archiva.
    $this->actingAs($owner)->deleteJson($this->api("/{$id}"))->assertOk()->assertJsonPath('archived', true);
    $draft = $this->actingAs($owner)->postJson($this->api(), ['name' => 'Borrador'])->json('id');
    $this->actingAs($owner)->deleteJson($this->api("/{$draft}"))->assertOk()->assertJsonPath('deleted', true);

    // Permisos: un lector ve pero no edita.
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($this->api())->assertOk();
    $this->actingAs($viewer)->postJson($this->api(), ['name' => 'x'])->assertForbidden();
});

it('la biblioteca muestra los 20 flujos, avisa las plantillas que faltan y activa en un clic', function () {
    $library = $this->actingAs($this->owner)->getJson($this->api('/library'))->assertOk();
    $cart = collect($library->json('data'))->firstWhere('key', 'abandoned_cart');
    expect($library->json('data'))->toHaveCount(20)->and($cart['available'])->toBeTrue()->and($cart['missing_templates'])->toBe([])
        ->and(collect($library->json('data'))->firstWhere('key', 'nps')['phase'])->toBe(3);

    $res = $this->actingAs($this->owner)->postJson($this->api('/library/abandoned_cart'), ['activate' => true])->assertCreated();
    expect($res->json('activated'))->toBeTrue()->and($res->json('status'))->toBe('active')->and($res->json('template_key'))->toBe('abandoned_cart');

    // Sin las plantillas, queda en borrador con los pendientes.
    DB::table('templates')->where('library_key', 'email_post_purchase')->delete();
    $draft = $this->actingAs($this->owner)->postJson($this->api('/library/post_purchase'), ['activate' => true])->assertCreated();
    expect($draft->json('activated'))->toBeFalse()->and($draft->json('status'))->toBe('draft')->and($draft->json('issues'))->not->toBeEmpty();
    $this->actingAs($this->owner)->postJson($this->api('/library/nps'))->assertUnprocessable();
});

it('modo prueba: recorre el borrador sin esperas y los mensajes van a quien prueba', function () {
    $fake = FakeMessageDispatcher::install();
    $id = $this->actingAs($this->owner)->postJson($this->api('/library/abandoned_cart'))->json('id');
    $res = $this->actingAs($this->owner)->postJson($this->api("/{$id}/test"), ['contact_id' => $this->contact->publicId(), 'phone' => '+573005550000'])->assertOk();
    expect($res->json('status'))->toBe('completed')
        ->and(collect($res->json('steps'))->pluck('outcome')->unique()->values()->all())->toBe(['continue', 'sent'])
        ->and($fake->dispatched)->toHaveCount(3)
        ->and($fake->dispatched[0]->to)->toBe('+573005550000')->and($fake->dispatched[0]->isTest)->toBeTrue()
        ->and($fake->dispatched[1]->to)->toBe($this->owner->email)
        ->and($fake->dispatched[2]->context['cupon']['codigo'])->toStartWith('PRUEBA-');
    expect(DB::table('coupons_issued')->count())->toBe(0);
    // Las pruebas no cuentan en la analítica.
    $this->actingAs($this->owner)->getJson($this->api("/{$id}/analytics"))->assertJsonPath('summary.entered', 0);
});

it('disparadores por fecha: cumpleaños a la hora configurada, una sola vez por día', function () {
    FakeMessageDispatcher::install();
    app(CurrentWorkspace::class)->run($this->ws, fn () => $this->contact->forceFill(['birthday' => '1990-11-17'])->save());
    $id = $this->actingAs($this->owner)->postJson($this->api('/library/birthday'), ['activate' => true])->json('id');

    $this->artisan('igr:flows:date-triggers', ['--now' => CarbonImmutable::parse('2026-11-17 08:00', 'America/Bogota')->toIso8601String()])->assertSuccessful();
    expect(FlowRun::withoutWorkspaceScope()->count())->toBe(0); // antes de las 10:00
    foreach (['10:05', '11:05'] as $time) {
        $this->artisan('igr:flows:date-triggers', ['--now' => CarbonImmutable::parse("2026-11-17 {$time}", 'America/Bogota')->toIso8601String()])->assertSuccessful();
    }
    $runs = FlowRun::withoutWorkspaceScope()->get();
    expect($runs)->toHaveCount(1)->and($runs[0]->trigger_key)->toBe('date:'.$this->contact->id.':2026-11-17')->and($id)->toStartWith('fl_');
});

it('webhook de entrada: con el token del flujo hace entrar a un contacto existente', function () {
    FakeMessageDispatcher::install();
    $id = $this->actingAs($this->owner)->postJson($this->api(), ['name' => 'Desde el CRM'])->json('id');
    $graph = ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 't', 'type' => 'tag', 'config' => ['add' => ['crm']]]], 'edges' => [['from' => 'trigger', 'to' => 't']]];
    $this->actingAs($this->owner)->patchJson($this->api("/{$id}"), ['trigger' => ['type' => 'webhook'], 'reentry_policy' => ['mode' => 'always'], 'graph' => $graph])->assertOk();
    $this->actingAs($this->owner)->postJson($this->api("/{$id}/publish"))->assertOk();
    $url = $this->actingAs($this->owner)->postJson($this->api("/{$id}/activate"))->json('webhook.url');
    $path = parse_url((string) $url, PHP_URL_PATH);

    $this->postJson($path, ['email' => 'ANA@ejemplo.com', 'data' => ['plan' => 'pro', 'email' => 'x@y.co']])->assertStatus(202)->assertJsonPath('entered', true);
    expect($this->contact->refresh()->getAttribute('tags'))->toContain('crm');
    $this->postJson($path, ['email' => 'nadie@ejemplo.com'])->assertNotFound();
    $this->postJson('/webhooks/flows/'.str_replace('.', '.x', (string) basename((string) $path)), ['email' => 'ana@ejemplo.com'])->assertNotFound();
    expect(FlowSecrets::verifyInbound('fl_nada.abc'))->toBeNull();
});

it('aislamiento: los flujos de otro espacio de trabajo no existen para la API ni para sus eventos', function () {
    FakeMessageDispatcher::install();
    $mine = $this->actingAs($this->owner)->postJson($this->api('/library/abandoned_cart'), ['activate' => true])->json('id');
    $otherOwner = $this->userWith2fa();
    $other = $this->workspaceFor($otherOwner, 'Otra tienda');
    $otherApi = "/api/w/{$other->publicId()}/flows";
    $this->actingAs($otherOwner)->getJson("{$otherApi}/{$mine}")->assertNotFound();
    $this->actingAs($otherOwner)->postJson("{$otherApi}/{$mine}/pause")->assertNotFound();
    expect($this->actingAs($otherOwner)->getJson($otherApi)->json('data'))->toBe([]);

    // Un cart.abandoned del otro espacio de trabajo no entra a mi flujo.
    $ws = $this->ws;
    $this->ws = $other;
    [$site] = $this->connectedSite($other, 'otra.co');
    $this->site = $site;
    $contact = $this->flowContact('pepe@otra.co', null, []);
    $this->flowEvent('cart.abandoned', $this->cartProps(), $contact);
    $this->ws = $ws;
    expect(FlowRun::withoutWorkspaceScope()->count())->toBe(0)
        ->and(Flow::withoutWorkspaceScope()->where('workspace_id', $other->id)->count())->toBe(0);
});
