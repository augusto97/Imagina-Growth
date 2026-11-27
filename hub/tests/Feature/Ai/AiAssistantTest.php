<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Features\Assistant\AssistantContext;
use App\Domain\Intelligence\Ai\Features\Assistant\AssistantKnowledge;
use App\Domain\Intelligence\Ai\Features\Assistant\AssistantPages;
use App\Domain\Intelligence\Ai\Features\Assistant\AssistantPlan;
use App\Domain\Intelligence\Ai\Features\Models\AskDataQuery;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** El último mensaje del pedido (lo que lleva la cuenta, la pantalla y la pregunta). */
function assistantLast(array $requestEntry): string
{
    $messages = $requestEntry['request']->messages;

    return (string) ($messages[count($messages) - 1]['content'] ?? '');
}

/** La respuesta JSON del modelo (salida estructurada). */
function assistantJson(string $answer, array $steps = [], string $kind = 'none', string $prompt = ''): array
{
    return ['answer' => $answer, 'steps' => array_map(fn (array $s) => $s + ['ref' => ''], $steps), 'action' => ['kind' => $kind, 'prompt' => $prompt]];
}

function assistantWhatsApp($ws, string $provider = 'meta_cloud'): void
{
    DB::table('channel_accounts')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $ws->id, 'channel' => 'whatsapp', 'provider' => $provider,
        'display_name' => 'WhatsApp', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
}

/*
 * «Pregúntale a la IA» desde cualquier pantalla (inteligencia.md §6.3): sabe del producto y de la cuenta, nunca deja una
 * burbuja vacía, los pasos llevan a pantallas que existen, las cifras se validan (la IA no inventa números) y, si la IA
 * falla, lo dice en palabras y deja reintentar.
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

it('«como inicio?» responde con los próximos pasos según la cuenta, cada uno con su pantalla', function () {
    $ai = FakeAiGateway::install()->replyJson(assistantJson(
        'Para empezar, conecta tu web y un canal para escribirles a tus clientes; después prende una estrategia.',
        [['text' => 'Conecta tu web con el plugin de WordPress.', 'page' => 'connections_web'], ['text' => 'Conecta WhatsApp.', 'page' => 'connections_whatsapp']],
    ));

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'como inicio?', 'page' => ['kind' => 'results']])->assertCreated();

    expect($res->json('status'))->toBe('answered')->and($res->json('source'))->toBe('ai')->and($res->json('answer'))->toContain('conecta tu web')
        ->and($res->json('steps'))->toBe([
            ['text' => 'Conecta tu web con el plugin de WordPress.', 'page' => 'connections_web', 'ref' => null, 'path' => 'conexiones?tarjeta=web'],
            ['text' => 'Conecta WhatsApp.', 'page' => 'connections_whatsapp', 'ref' => null, 'path' => 'conexiones?tarjeta=whatsapp'],
        ])
        ->and($res->json('retryable'))->toBeFalse();
    $entry = $ai->requests[0];
    $last = assistantLast($entry);
    // La salida es JSON y la única herramienta es la de cifras: ya no hay una herramienta para proponer el paso.
    expect($entry['tools'])->toBe(['run_metric_query'])
        ->and($entry['request']->outputSchema['required'])->toBe(['answer', 'steps', 'action'])
        ->and($entry['request']->system)->toContain('Base de conocimiento')->toContain('connections_web: /conexiones?tarjeta=web')
        ->and($last)->toContain('Estado de la cuenta')->toContain('"web":"sin conectar"')->toContain('"whatsapp":"sin conectar"')
        ->and($last)->toContain('Próximos pasos sugeridos')->toContain('[page: connections_web]');
});

it('una respuesta sin texto se pide otra vez y, si vuelve vacía, responde los próximos pasos sin IA (nunca una burbuja vacía)', function () {
    // Lo que pasaba en producción: el modelo dejaba solo el paso («Ir a Conexiones») y el texto vacío.
    $ai = FakeAiGateway::install()
        ->replyJson(assistantJson('', [['text' => 'Ir a Conexiones', 'page' => 'connections']]))
        ->replyJson(assistantJson('Conecta tu sitio desde Conexiones: descarga el plugin y pega el código.', [['text' => 'Conecta tu web.', 'page' => 'connections_web']]));
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", [
        'question' => 'ok pero quiero conectar un nuevo sitio y sacarle jugo a esta app con el',
        'page' => ['kind' => 'results'],
        'history' => [['question' => 'como inicio con la app?', 'answer' => 'Conecta tu web y un canal.']],
    ])->assertCreated();
    expect($res->json('answer'))->toBe('Conecta tu sitio desde Conexiones: descarga el plugin y pega el código.')
        ->and($res->json('steps.0.path'))->toBe('conexiones?tarjeta=web')
        ->and($ai->requests)->toHaveCount(2)
        ->and(assistantLast($ai->requests[1]))->toContain('sin texto en «answer»')
        // El seguimiento («ok pero…») llega con la conversación anterior.
        ->and($ai->requests[0]['request']->messages[0]['content'])->toBe('como inicio con la app?');

    FakeAiGateway::install()->replyJson(assistantJson(''))->replyJson(assistantJson('   '));
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'como inicio?'])->assertCreated();
    expect($res->json('status'))->toBe('answered')->and($res->json('source'))->toBe('fallback')->and($res->json('fallback_reason'))->toBe('empty')
        ->and($res->json('answer'))->toContain('próximos pasos')
        ->and($res->json('steps.0'))->toMatchArray(['page' => 'connections_web', 'path' => 'conexiones?tarjeta=web'])
        ->and(count($res->json('steps')))->toBeLessThanOrEqual(3);

    // Una respuesta que no es el JSON pedido (cortada) también se pide otra vez y, si sigue mal, tampoco queda vacía.
    FakeAiGateway::install()->failWith('invalid')->failWith('invalid');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'como inicio con la app?'])->assertCreated();
    expect($res->json('answer'))->not->toBe('')->and($res->json('fallback_reason'))->toBe('invalid')->and($res->json('steps'))->not->toBeEmpty();
});

it('«¿cómo conecto WhatsApp?» usa lo que ya está conectado: sin WhatsApp lo sugiere; con WhatsApp oficial, no', function () {
    $ai = FakeAiGateway::install()->replyJson(assistantJson('Pulsa «Conectar WhatsApp con un clic» y elige tu número.', [['text' => 'Conecta WhatsApp.', 'page' => 'connections_whatsapp']]));
    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cómo conecto WhatsApp?'])->assertCreated();
    expect(assistantLast($ai->requests[0]))->toContain('"whatsapp":"sin conectar"')->toContain('[page: connections_whatsapp]');

    assistantWhatsApp($this->ws);
    $ai = FakeAiGateway::install()->replyJson(assistantJson('Tu WhatsApp oficial ya está conectado. Ahora prende una automatización.'));
    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cómo conecto WhatsApp?'])->assertCreated();
    expect(assistantLast($ai->requests[0]))->toContain('"whatsapp":"oficial (API de Meta)"')->not->toContain('[page: connections_whatsapp]');

    // Con WhatsApp no oficial tampoco lo vuelve a pedir; caído, pide reconectarlo.
    expect(array_column(AssistantPlan::steps(['whatsapp' => 'no oficial (solo texto)', 'correo' => 'sin conectar', 'web' => 'conectada']), 'page'))->not->toContain('connections_whatsapp')
        ->and(AssistantPlan::steps(['whatsapp' => 'desconectado: hay que reconectarlo', 'web' => 'conectada'])[0]['text'])->toContain('Reconecta');
});

it('responde cifras con la herramienta y las valida; un paso a una pantalla que no existe queda sin enlace', function () {
    $this->useIngestInfrastructure();
    $input = ['metrics' => ['orders'], 'dimensions' => [], 'from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString(),
        'compare' => null, 'granularity' => 'day', 'channel' => null, 'device' => null, 'city' => null, 'limit' => 10];
    $ai = FakeAiGateway::install()->callTool('run_metric_query', $input)->replyJson(assistantJson(
        'En los últimos 30 días tuviste 0 pedidos.',
        [['text' => 'Mira el detalle en tus tableros.', 'page' => 'tablero_inventado'], ['text' => 'Prende la recuperación de carritos.', 'page' => 'strategy', 'ref' => 'abandoned_cart']],
    ));
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cuántos pedidos tuve en los últimos 30 días?'])->assertCreated();
    expect($res->json('source'))->toBe('ai')->and($res->json('query.metrics'))->toBe(['orders'])
        ->and($res->json('steps.0'))->toBe(['text' => 'Mira el detalle en tus tableros.', 'page' => null, 'ref' => null, 'path' => null])
        ->and($res->json('steps.1'))->toBe(['text' => 'Prende la recuperación de carritos.', 'page' => 'strategy', 'ref' => 'abandoned_cart', 'path' => 'estrategias/abandoned_cart'])
        ->and($ai->requests[0]['tools'])->toBe(['run_metric_query']);

    // Una estrategia que no existe no lleva a ningún lado.
    expect(AssistantPages::link('strategy', 'no_existe'))->toBeNull()->and(AssistantPages::link('strategy'))->toBeNull()
        ->and(AssistantPages::link('home'))->toBe(['page' => 'home', 'ref' => null, 'path' => '']);
});

it('responde con los hechos del perfil que está viendo, sin nombre ni correo, y propone un borrador con el copiloto', function () {
    $ai = FakeAiGateway::install()->replyJson(assistantJson(
        'Este cliente lleva 4 pedidos y gasta en promedio $120.000. Es leal: invítalo a tu programa de puntos.', [],
        'campaign', 'Campaña para clientes leales con ticket de $120.000',
    ));

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", [
        'question' => '¿Qué hago con este cliente?',
        'page' => ['kind' => 'contact', 'id' => $this->contact->publicId()],
        'history' => [['question' => '¿Cuántos clientes tengo?', 'answer' => 'Tienes 30 clientes.']],
    ])->assertCreated();

    expect($res->json('status'))->toBe('answered')->and($res->json('source'))->toBe('ai')->and($res->json('context'))->toBe('contact')
        ->and($res->json('actions'))->toBe([['kind' => 'campaign', 'prompt' => 'Campaña para clientes leales con ticket de $120.000', 'page' => null, 'path' => null]]);
    $request = $ai->requests[0]['request'];
    $last = assistantLast($ai->requests[0]);
    expect($request->feature)->toBe('assistant')
        ->and($request->messages[0]['content'])->toBe('¿Cuántos clientes tengo?')
        ->and($last)->toContain('Pantalla donde está la persona: contact')->toContain('"pedidos":4')->toContain('segmento_rfm')
        ->and($last)->not->toContain('Laura')->not->toContain('laura@cafe.test');
    $q = app(CurrentWorkspace::class)->run($this->ws, fn () => AskDataQuery::query()->firstOrFail());
    expect($q->context)->toBe(['kind' => 'contact'])->and($q->question)->toBe('¿Qué hago con este cliente?');
});

it('un contacto de otro espacio de trabajo no da hechos, una pantalla que no existe se ignora y sin permiso de IA no pregunta', function () {
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    $foreign = app(CurrentWorkspace::class)->run($other, fn () => Contact::query()->create(['workspace_id' => $other->id, 'first_name' => 'Ajeno', 'orders_count' => 9]));
    $ai = FakeAiGateway::install()->replyJson(assistantJson('No tengo datos de esta pantalla.'))->replyJson(assistantJson('Listo.'));
    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Quién es?', 'page' => ['kind' => 'contact', 'id' => $foreign->publicId()]])->assertCreated();
    expect(assistantLast($ai->requests[0]))->not->toContain('"pedidos":9')->not->toContain('Hechos de la pantalla');

    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Y este grupo?', 'page' => ['kind' => 'segment', 'id' => 'seg_01J000000000000000000000AA']])->assertCreated();
    expect(assistantLast($ai->requests[1]))->not->toContain('Hechos de la pantalla');

    $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola', 'page' => ['kind' => 'otra']])->assertUnprocessable();
    foreach ([WorkspaceRole::Viewer, WorkspaceRole::Agent] as $role) {
        $this->actingAs($this->addMember($this->ws, $role, true))->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertForbidden();
    }
});

it('quien no puede configurar recibe los pasos para pedírselos al dueño', function () {
    $marketer = $this->addMember($this->ws, WorkspaceRole::Marketing, true);
    $ai = FakeAiGateway::install()->replyJson(assistantJson(''))->replyJson(assistantJson(''));
    $res = $this->actingAs($marketer)->postJson("{$this->api}/ai/assistant", ['question' => '¿cómo empiezo?'])->assertCreated();
    expect(assistantLast($ai->requests[0]))->toContain('"puede_configurar":false')
        ->and($res->json('answer'))->toContain('dueño o un administrador');
});

it('los hechos de un grupo de clientes llegan con su tamaño', function () {
    $segment = app(CurrentWorkspace::class)->run($this->ws, fn () => Segment::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Leales de Manizales', 'kind' => 'static', 'member_count' => 37, 'revenue_ws' => '1500000']));
    $ai = FakeAiGateway::install()->replyJson(assistantJson('Este grupo tiene 37 personas. Escríbeles con una campaña.', [['text' => 'Crea una campaña para este grupo.', 'page' => 'campaigns']]));
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cuántos son?', 'page' => ['kind' => 'segment', 'id' => $segment->publicId()]])->assertCreated();
    expect($res->json('steps.0.path'))->toBe('campanas')->and(assistantLast($ai->requests[0]))->toContain('"personas":37');
});

it('si inventa una cifra se le corrige una vez y, si insiste, no la muestra; un borrador con cifras inventadas no se ofrece', function () {
    $ai = FakeAiGateway::install()
        ->replyJson(assistantJson('Vas a recuperar $9.000.000 este mes.'))
        ->replyJson(assistantJson('Recuperarás 250 carritos.', [], 'flow', 'Recupera 250 carritos'));
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cuánto voy a recuperar?'])->assertCreated();
    expect($res->json('source'))->toBe('fallback')->and($res->json('fallback_reason'))->toBe('numbers')
        ->and($res->json('answer'))->not->toContain('9.000.000')->not->toContain('250')
        ->and($res->json('answer'))->not->toBe('')
        ->and($res->json('actions'))->toBe([])
        ->and($ai->requests)->toHaveCount(2)
        ->and(assistantLast($ai->requests[1]))->toContain('$9.000.000');

    // Las cifras de la base de conocimiento (topes, horas) sí se pueden decir.
    FakeAiGateway::install()->replyJson(assistantJson('El WhatsApp no oficial permite 30 mensajes por hora y 200 al día.'));
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => '¿Cuántos mensajes manda el WhatsApp no oficial?'])->assertCreated();
    expect($res->json('source'))->toBe('ai')->and(AssistantKnowledge::numbers())->toContain(30.0)->toContain(200.0);
});

it('si la IA falla lo dice en palabras y deja reintentar; un rechazo pide preguntarlo de otra forma', function () {
    FakeAiGateway::install()->failWith('provider');
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'como inicio?'])->assertCreated();
    expect($res->json('status'))->toBe('failed')->and($res->json('fallback_reason'))->toBe('provider')
        ->and($res->json('retryable'))->toBeTrue()->and($res->json('answer'))->toContain('Inténtalo de nuevo');

    FakeAiGateway::install()->refuse();
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'algo raro'])->assertCreated();
    expect($res->json('status'))->toBe('failed')->and($res->json('fallback_reason'))->toBe('refusal')
        ->and($res->json('retryable'))->toBeFalse()->and($res->json('answer'))->toContain('de otra forma');
});

it('con la IA apagada dice cómo encenderla según quién pregunta, sin llamar al modelo', function () {
    $ai = FakeAiGateway::install();
    $ai->enabled = false;
    config(['igr-ai.api_key' => null]);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertCreated();
    expect($res->json('status'))->toBe('failed')->and($res->json('fallback_reason'))->toBe('disabled')->and($res->json('answer'))->toContain('quien la administra')
        ->and($res->json('retryable'))->toBeFalse();

    $admin = $this->userWith2fa(['is_platform_admin' => true]);
    $this->ws->users()->attach($admin->id, ['role' => WorkspaceRole::Owner->value]);
    $res = $this->actingAs($admin)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertCreated();
    expect($res->json('answer'))->toContain('Plataforma → Ajustes → IA');

    config(['igr-ai.enabled' => true, 'igr-ai.api_key' => 'sk-ant-prueba']);
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/ai/assistant", ['question' => 'Hola'])->assertCreated();
    expect($res->json('fallback_reason'))->toBe('budget')->and($ai->requests)->toBe([]);
});

it('la base de conocimiento solo nombra pantallas del catálogo y el catálogo es el enum del esquema', function () {
    preg_match_all('/\[([a-z_]+)\]/', (string) file_get_contents(resource_path('assistant/knowledge.md')), $m);
    expect(array_diff(array_unique($m[1]), AssistantPages::keys()))->toBe([])
        ->and(AssistantKnowledge::schema()['properties']['steps']['items']['properties']['page']['enum'])->toBe([...AssistantPages::keys(), 'none']);
});

it('aplana los hechos: solo lo escalar y corto, con claves de ruta', function () {
    $flat = AssistantContext::flatten(['a' => ['b' => 1, 'c' => ['d' => 'x']], 'largo' => str_repeat('y', 200), 'lista' => [1, 2]]);
    expect($flat)->toBe(['a.b' => 1, 'a.c.d' => 'x', 'lista.0' => 1, 'lista.1' => 2]);
});
