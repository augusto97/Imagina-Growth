<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Features\Assistant\AssistantContext;
use App\Domain\Intelligence\Ai\Features\Models\AskDataQuery;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;

/** El último mensaje del pedido (lo que lleva la pantalla y la pregunta). */
function assistantLast(array $requestEntry): string
{
    $messages = $requestEntry['request']->messages;

    return (string) ($messages[count($messages) - 1]['content'] ?? '');
}

/*
 * «Pregúntale a la IA» desde cualquier pantalla (inteligencia.md §6.3): hechos de la pantalla según el rol, cifras
 * validadas (la IA no inventa números), un siguiente paso que la persona revisa, y qué hacer si la IA está apagada.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->api = "/api/w/{$this->ws->publicId()}";
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create([
        'workspace_id' => $this->ws->id, 'first_name' => 'Laura Gómez', 'email' => 'laura@cafe.test', 'orders_count' => 4,
        'revenue_total_ws' => 480000, 'aov_ws' => 120000, 'rfm_segment' => 'loyal', 'churn_band' => 'low',
    ]));
});

it('responde con los hechos del perfil que está viendo, sin nombre ni correo, y propone un borrador con el copiloto', function () {
    $ai = FakeAiGateway::install()
        ->callTool('suggest_next_step', ['kind' => 'campaign', 'prompt' => 'Campaña para clientes leales con ticket de $120.000', 'page' => 'none'])
        ->reply('Este cliente lleva 4 pedidos y gasta en promedio $120.000. Es leal: invítalo a tu programa de puntos.');

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", [
        'question' => '¿Qué hago con este cliente?',
        'page' => ['kind' => 'contact', 'id' => $this->contact->publicId()],
        'history' => [['question' => '¿Cuántos clientes tengo?', 'answer' => 'Tienes 30 clientes.']],
    ])->assertCreated();

    expect($res->json('status'))->toBe('answered')->and($res->json('source'))->toBe('ai')
        ->and($res->json('context'))->toBe('contact')
        ->and($res->json('actions'))->toBe([['kind' => 'campaign', 'prompt' => 'Campaña para clientes leales con ticket de $120.000', 'page' => null]]);
    $request = $ai->requests[0]['request'];
    $last = assistantLast($ai->requests[0]);
    expect($request->feature)->toBe('assistant')
        ->and($ai->requests[0]['tools'])->toBe(['run_metric_query', 'suggest_next_step'])
        // La conversación del panel va antes de la pregunta.
        ->and($request->messages[0]['content'])->toBe('¿Cuántos clientes tengo?')
        ->and($last)->toContain('Pantalla: contact')->toContain('"pedidos":4')->toContain('segmento_rfm')
        ->and($last)->not->toContain('Laura')->not->toContain('laura@cafe.test');
    // Queda en el historial de «Pregúntale», con el tipo de pantalla y sin los hechos.
    $q = app(CurrentWorkspace::class)->run($this->ws, fn () => AskDataQuery::query()->firstOrFail());
    expect($q->context)->toBe(['kind' => 'contact'])->and($q->question)->toBe('¿Qué hago con este cliente?');
});

it('un contacto de otro espacio de trabajo no da hechos, una pantalla que no existe se ignora y sin permiso de IA no pregunta', function () {
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    $foreign = app(CurrentWorkspace::class)->run($other, fn () => Contact::query()->create(['workspace_id' => $other->id, 'first_name' => 'Ajeno', 'orders_count' => 9]));
    $ai = FakeAiGateway::install()->reply('No tengo datos de esta pantalla.')->reply('Listo.');
    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Quién es?', 'page' => ['kind' => 'contact', 'id' => $foreign->publicId()]])->assertCreated();
    expect(assistantLast($ai->requests[0]))->not->toContain('pedidos');

    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Y este grupo?', 'page' => ['kind' => 'segment', 'id' => 'seg_01J000000000000000000000AA']])->assertCreated();
    expect(assistantLast($ai->requests[1]))->not->toContain('Hechos de la pantalla');

    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola', 'page' => ['kind' => 'otra']])->assertUnprocessable();
    // El agente y el lector no usan la IA (rol: `useAi`).
    foreach ([WorkspaceRole::Viewer, WorkspaceRole::Agent] as $role) {
        $this->actingAs($this->addMember($this->ws, $role, true))->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertForbidden();
    }
});

it('los hechos de un grupo de clientes llegan con su tamaño, y un paso a una pantalla se ofrece como enlace', function () {
    $segment = app(CurrentWorkspace::class)->run($this->ws, fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Leales de Manizales', 'kind' => 'static', 'member_count' => 37, 'revenue_ws' => '1500000']));
    $ai = FakeAiGateway::install()
        ->callTool('suggest_next_step', ['kind' => 'page', 'prompt' => '', 'page' => 'campaigns'])
        ->reply('Este grupo tiene 37 personas. Escríbeles con una campaña.');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cuántos son?', 'page' => ['kind' => 'segment', 'id' => $segment->publicId()]])->assertCreated();
    expect($res->json('actions'))->toBe([['kind' => 'page', 'prompt' => null, 'page' => 'campaigns']])
        ->and(assistantLast($ai->requests[0]))->toContain('"personas":37');
});

it('si inventa una cifra se le corrige una vez y, si insiste, no la muestra; un paso con cifras inventadas no se ofrece', function () {
    $ai = FakeAiGateway::install()
        ->callTool('suggest_next_step', ['kind' => 'flow', 'prompt' => 'Recupera 250 carritos', 'page' => 'none'])
        ->reply('Vas a recuperar $9.000.000 este mes.')
        ->reply('Recuperarás 250 carritos.');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cuánto voy a recuperar?'])->assertCreated();
    expect($res->json('source'))->toBe('fallback')->and($res->json('fallback_reason'))->toBe('numbers')
        ->and($res->json('answer'))->not->toContain('9.000.000')->not->toContain('250')
        ->and($res->json('actions'))->toBe([])
        ->and($ai->requests)->toHaveCount(2)
        ->and(assistantLast($ai->requests[1]))->toContain('$9.000.000');

    FakeAiGateway::install()->callTool('suggest_next_step', ['kind' => 'flow', 'prompt' => 'Recupera 250 carritos', 'page' => 'none'])->reply('Activa la recuperación de carritos.');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Qué activo?'])->assertCreated();
    expect($res->json('source'))->toBe('ai')->and($res->json('actions'))->toBe([]);
});

it('con la IA apagada dice cómo encenderla según quién pregunta, sin llamar al modelo', function () {
    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    config(['igr-ai.api_key' => null]);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertCreated();
    expect($res->json('status'))->toBe('failed')->and($res->json('fallback_reason'))->toBe('disabled')->and($res->json('answer'))->toContain('quien la administra');

    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    $this->ws->users()->attach($admin->id, ['role' => WorkspaceRole::Owner->value]);
    $res = $this->actingAs($admin)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertCreated();
    expect($res->json('answer'))->toContain('Plataforma → Ajustes → IA');

    config(['igr-ai.enabled' => true, 'igr-ai.api_key' => 'sk-ant-prueba']);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertCreated();
    expect($res->json('fallback_reason'))->toBe('budget')->and($ai->requests)->toBe([]);
});

it('aplana los hechos: solo lo escalar y corto, con claves de ruta', function () {
    $flat = AssistantContext::flatten(['a' => ['b' => 1, 'c' => ['d' => 'x']], 'largo' => str_repeat('y', 200), 'lista' => [1, 2]]);
    expect($flat)->toBe(['a.b' => 1, 'a.c.d' => 'x', 'lista.0' => 1, 'lista.1' => 2]);
});
