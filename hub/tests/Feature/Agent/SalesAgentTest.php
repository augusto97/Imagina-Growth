<?php

use App\Domain\Commerce\Models\Cart;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Agent\Console\AgentEvalCommand;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Agent\Models\AgentTurn;
use App\Domain\Intelligence\Ai\Agent\SalesAgent;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->account = ($this->in)(fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WA', 'status' => 'active', 'external_id' => '1001']));
    $this->contact = ($this->in)(fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '+573001234567']));
    $this->conversation = ($this->in)(fn () => Conversation::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel_account_id' => $this->account->id,
        'status' => 'open', 'ai_mode' => 'auto', 'window_expires_at' => now()->addHours(20), 'last_inbound_at' => now()]));
    ($this->in)(fn () => AgentSettings::query()->create(['workspace_id' => $this->ws->id, 'enabled' => true, 'default_mode' => 'auto', 'max_steps' => 6,
        'policies' => ['shipping' => 'Envío gratis desde $150.000; a Bogotá llega en 2 días hábiles.', 'payment_methods' => 'PSE, Nequi, tarjeta y contraentrega.'],
        'handoff_rules' => AgentSettings::defaultHandoff(), 'discount_rules' => ['enabled' => false]]));
    $this->dispatcher = FakeMessageDispatcher::install();
    // El clasificador de IA (fork N) consumiría el guion del modelo: aquí, el de palabras.
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    $this->commands = [];
    Http::fake(['tienda.co/*' => function (HttpRequest $request) {
        $body = json_decode($request->body(), true);
        $this->commands[] = $body;

        return match ($body['command']) {
            'product.lookup' => Http::response(['ok' => true, 'data' => ['products' => [
                ['id' => '88', 'name' => 'Pintura Azul Galón', 'sku' => 'PA-1', 'price' => '141000.00', 'regular_price' => '150000.00', 'sale_price' => '141000.00',
                    'stock_status' => 'instock', 'stock_qty' => 12, 'url' => 'https://tienda.co/p/88', 'variations' => []],
            ]]]),
            'order.status' => ($body['args']['phone'] ?? null) === '+573001234567' && ($body['args']['number'] ?? null) === '10683'
                ? Http::response(['ok' => true, 'data' => ['status' => 'processing', 'status_label' => 'En preparación', 'total' => '282000.00', 'currency' => 'COP', 'created_at' => '2026-11-15T10:00:00Z']])
                : Http::response(['ok' => false, 'error' => ['code' => 'not_found']], 404),
            default => Http::response(['ok' => false], 400),
        };
    }]);
});

function agentInbound($test, string $text): Message
{
    return ($test->in)(fn () => Message::query()->create([
        'workspace_id' => $test->ws->id, 'contact_id' => $test->contact->id, 'channel_account_id' => $test->account->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
        'provider_message_id' => 'wamid.'.uniqid(), 'direction' => 'in', 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
        'payload' => ['type' => 'text', 'text' => $text], 'conversation_id' => $test->conversation->id,
    ]));
}

function agentRun($test, string $text): AgentTurn
{
    $inbound = agentInbound($test, $text);
    ($test->in)(fn () => app(ConversationAgent::class)->onInbound($test->conversation->fresh(), $inbound));

    return ($test->in)(fn () => AgentTurn::query()->where('inbound_message_id', $inbound->id)->firstOrFail());
}

it('reemplaza al agente nulo de la bandeja', function () {
    expect(app(ConversationAgent::class))->toBeInstanceOf(SalesAgent::class);
});

it('pregunta el precio: consulta la tienda en vivo y responde con la cifra de la herramienta', function () {
    $ai = FakeAiGateway::install()->callTool('get_product', ['product_id' => '88', 'search' => null])
        ->reply('La Pintura Azul Galón está en $141.000 (antes $150.000) y hay 12 unidades.');
    $turn = agentRun($this, '¿Cuánto vale la pintura azul de galón?');

    expect($turn->status)->toBe('replied')->and($turn->validation['ok'])->toBeTrue()
        ->and($this->commands[0]['command'])->toBe('product.lookup')
        ->and($this->dispatcher->dispatched)->toHaveCount(1)
        ->and($this->dispatcher->dispatched[0]->kind->value)->toBe('service')
        ->and($this->dispatcher->dispatched[0]->context['text'])->toContain('$141.000')
        ->and($this->dispatcher->dispatched[0]->idempotencyKey)->toBe('agent:'.$turn->id)
        ->and($ai->requests[0]['tools'])->toContain('get_product', 'handoff_to_human')
        ->and($ai->requests[0]['request']->messages[0]['content'])->toBe('¿Cuánto vale la pintura azul de galón?');
    // El system no lleva datos de la persona ni de la hora: se puede cachear.
    expect($ai->requests[0]['request']->system)->toContain('Nunca inventes precios')->not->toContain('Ana');
});

it('si inventa un precio se corrige una vez; si insiste, pasa a un humano sin enviar la cifra', function () {
    FakeAiGateway::install()->callTool('get_product', ['product_id' => '88', 'search' => null])->reply('Cuesta $99.000.')
        ->reply('Te queda en $95.000 con envío.');
    $turn = agentRun($this, '¿Precio de la pintura azul?');

    expect($turn->status)->toBe('handoff')->and($turn->handoff_reason)->toBe('unverified_numbers')
        ->and($turn->validation['attempts'][0]['unknown'])->toBe(['$99.000'])
        ->and($this->dispatcher->dispatched)->toHaveCount(1)
        ->and($this->dispatcher->dispatched[0]->context['text'])->not->toContain('95.000')
        ->and($this->conversation->fresh()->ai_mode)->toBe('off')
        ->and($this->conversation->fresh()->handoff_reason)->toBe('unverified_numbers')
        ->and(DB::table('conversation_events')->where('conversation_id', $this->conversation->id)->where('kind', 'handoff')->count())->toBe(1);
});

it('un precio o stock sin consulta en vivo se bloquea, aunque la cifra salga de la búsqueda', function () {
    FakeAiGateway::install()->reply('Sí, hay 12 unidades disponibles.')->reply('Quedan 12 unidades.');
    $turn = agentRun($this, '¿Hay pintura azul? quiero 12');

    expect($turn->status)->toBe('handoff')->and($turn->validation['attempts'][0]['needs_lookup'])->toBeTrue()
        ->and(collect($this->dispatcher->dispatched)->pluck('context.text')->implode(' '))->not->toContain('12 unidades');
});

it('fuera del negocio responde con amabilidad sin usar herramientas; las políticas no exigen consulta', function () {
    FakeAiGateway::install()->reply('Solo puedo ayudarte con productos y pedidos de la tienda. ¿Buscas alguna pintura?');
    expect(agentRun($this, '¿Me ayudas con la tarea de matemáticas?')->status)->toBe('replied');

    FakeAiGateway::install()->reply('El envío es gratis desde $150.000 y a Bogotá llega en 2 días hábiles.');
    $turn = agentRun($this, '¿Cuánto cuesta el envío?');
    expect($turn->status)->toBe('replied')->and($turn->tool_calls)->toBe([]);
});

it('el estado del pedido solo se consulta con el número del chat', function () {
    FakeAiGateway::install()->callTool('get_order_status', ['order_number' => '10683'])->reply('Tu pedido 10683 está en preparación, por $282.000.');
    $turn = agentRun($this, '¿Cómo va mi pedido 10683? mi número es 3109998877');

    $call = collect($this->commands)->firstWhere('command', 'order.status');
    expect($turn->status)->toBe('replied')->and($call['args']['phone'])->toBe('+573001234567')->and($call['args']['number'])->toBe('10683');
});

it('el carrito nunca lleva un cupón que las reglas no autorizan; con regla, solo el autorizado', function () {
    FakeAiGateway::install()->callTool('create_cart_link', ['items' => [['product_id' => '88', 'variation_id' => null, 'qty' => 2]], 'apply_discount' => true])
        ->reply('Listo, son $282.000: https://tienda.co/?igr_cart=x');
    $turn = agentRun($this, 'Quiero 2 galones con descuento');
    $cart = ($this->in)(fn () => Cart::query()->findOrFail($turn->cart_id));
    expect($turn->status)->toBe('replied')->and($cart->coupons)->toBe([])->and($cart->status)->toBe('agent')->and((string) $cart->subtotal)->toBe('282000.00');

    ($this->in)(fn () => AgentSettings::query()->whereKey($this->ws->id)->update(['discount_rules' => json_encode(['enabled' => true, 'coupon_code' => 'WHATSAPP10', 'min_subtotal' => 200000, 'description' => '10% por comprar por WhatsApp'])]));
    FakeAiGateway::install()->callTool('create_cart_link', ['items' => [['product_id' => '88', 'variation_id' => null, 'qty' => 2]], 'apply_discount' => true])
        ->reply('Listo, con 10% por WhatsApp: https://tienda.co/?igr_cart=y');
    $turn = agentRun($this, 'Quiero 2 galones');
    expect(($this->in)(fn () => Cart::query()->findOrFail($turn->cart_id))->coupons)->toBe(['WHATSAPP10']);
});

it('con la ventana de 24 h cerrada pasa a un humano sin llamar al modelo', function () {
    ($this->in)(fn () => $this->conversation->forceFill(['window_expires_at' => now()->subHour()])->save());
    $ai = FakeAiGateway::install();
    $turn = agentRun($this, 'Hola, ¿tienen rodillos?');
    expect($turn->status)->toBe('handoff')->and($turn->handoff_reason)->toBe('window_closed')->and($ai->requests)->toBe([])->and($this->dispatcher->dispatched)->toBe([]);
});

it('en modo asistido deja la sugerencia cifrada y no envía nada', function () {
    ($this->in)(fn () => $this->conversation->forceFill(['ai_mode' => 'assist'])->save());
    FakeAiGateway::install()->callTool('get_product', ['product_id' => '88', 'search' => null])->reply('Está en $141.000.');
    $turn = agentRun($this, '¿Precio de la pintura azul?');
    expect($turn->status)->toBe('suggested')->and($turn->suggestion)->toBe('Está en $141.000.')->and($this->dispatcher->dispatched)->toBe([])
        ->and((string) DB::table('ai_agent_turns')->where('id', $turn->id)->value('suggestion'))->not->toContain('141');

    FakeAiGateway::install()->callTool('get_product', ['product_id' => '88', 'search' => null])->reply('Está en $141.000.');
    expect(($this->in)(fn () => app(ConversationAgent::class)->suggest($this->conversation->fresh())))->toBe('Está en $141.000.');
});

it('no responde a una baja, a quien pide un asesor le pasa con una persona y respeta el límite por hora', function () {
    $ai = FakeAiGateway::install();
    expect(agentRun($this, 'BAJA')->status)->toBe('blocked')->and($ai->requests)->toBe([])->and($this->dispatcher->dispatched)->toBe([]);

    $turn = agentRun($this, 'Quiero hablar con un asesor por favor');
    expect($turn->status)->toBe('handoff')->and($turn->handoff_reason)->toBe('keyword')->and($ai->requests)->toBe([])
        ->and($this->dispatcher->dispatched[0]->context['text'])->toContain('persona de nuestro equipo');

    ($this->in)(fn () => Conversation::query()->whereKey($this->conversation->id)->update(['ai_mode' => 'auto', 'handoff_reason' => null]));
    ($this->in)(function () {
        foreach (range(1, SalesAgent::MAX_TURNS_PER_HOUR) as $i) {
            AgentTurn::query()->create(['workspace_id' => $this->ws->id, 'conversation_id' => $this->conversation->id, 'mode' => 'auto', 'status' => 'replied']);
        }
    });
    // Los entrantes anteriores (la «BAJA» de hace un rato) no cuentan: solo el mensaje que se atiende.
    ($this->in)(fn () => Message::query()->where('conversation_id', $this->conversation->id)->update(['created_at' => now()->subMinutes(5)]));
    expect(agentRun($this, '¿Tienen brochas?')->handoff_reason)->toBe('rate_limit');
});

it('si el modelo pide pasar con un humano, la conversación queda sin IA y se avisa a la persona', function () {
    FakeAiGateway::install()->callTool('handoff_to_human', ['reason' => 'queja por pedido'])->reply('Te comunico con una persona del equipo.');
    $turn = agentRun($this, 'Tengo una pregunta legal sobre mi compra');
    expect($turn->status)->toBe('handoff')->and($turn->handoff_reason)->toBe('queja por pedido')
        ->and($this->conversation->fresh()->ai_mode)->toBe('off')->and($this->dispatcher->dispatched[0]->context['text'])->toBe('Te comunico con una persona del equipo.');
});

it('con el horario del equipo, dentro de él pasa a las personas y fuera responde', function () {
    $hours = fn (string $start, string $end) => ($this->in)(fn () => AgentSettings::query()->whereKey($this->ws->id)->update(['handoff_rules' => json_encode(
        AgentSettings::defaultHandoff() + ['hours' => ['mode' => 'out_of_hours', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start' => $start, 'end' => $end]])]));
    $hours('00:00', '23:59');
    expect(agentRun($this, 'Hola, ¿tienen rodillos?')->handoff_reason)->toBe('business_hours');

    ($this->in)(fn () => Conversation::query()->whereKey($this->conversation->id)->update(['ai_mode' => 'auto']));
    $hours('00:00', '00:01');
    FakeAiGateway::install()->reply('Sí, ¿de qué tamaño lo buscas?');
    expect(agentRun($this, 'Hola, ¿tienen rodillos?')->status)->toBe('replied');
})->skip(fn () => now('America/Bogota')->format('H:i') < '00:02', 'justo en el minuto del borde');

it('el horario de atención de Tu negocio manda sobre el guardado en el agente', function () {
    // El agente dice «nunca hay personas» (00:00 a 00:01); el negocio atiende todo el día, toda la semana.
    ($this->in)(fn () => AgentSettings::query()->whereKey($this->ws->id)->update(['handoff_rules' => json_encode(
        AgentSettings::defaultHandoff() + ['hours' => ['mode' => 'out_of_hours', 'days' => [1], 'start' => '00:00', 'end' => '00:01']])]));
    $api = "/api/w/{$this->ws->publicId()}";
    $this->actingAs($this->owner)->patchJson($api, ['business_hours' => ['days' => [7, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59']])->assertOk()
        ->assertJsonPath('settings.business_hours', ['days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '00:00', 'end' => '23:59']);
    expect(agentRun($this, 'Hola, ¿tienen rodillos?')->handoff_reason)->toBe('business_hours');

    $this->actingAs($this->owner)->patchJson($api, ['business_hours' => ['days' => [], 'start' => '18:00', 'end' => '08:00']])->assertUnprocessable()
        ->assertJsonValidationErrors(['business_hours.days', 'business_hours.end']);
    $show = $this->actingAs($this->owner)->getJson("{$api}/agent/settings")->assertOk();
    expect($show->json('business_hours.start'))->toBe('00:00')->and($show->json('channels'))->toBe(['whatsapp'])
        ->and($show->json('services'))->toBe(['qualification' => ['sets' => 0, 'active' => 0], 'booking' => ['module' => true, 'services' => 0]]);
})->skip(fn () => now('America/Bogota')->format('H:i') >= '23:59', 'justo en el minuto del borde');

it('apagado, en off o con un mensaje propio no hace nada', function () {
    $ai = FakeAiGateway::install();
    $send = function (string $text) {
        $inbound = agentInbound($this, $text);
        ($this->in)(fn () => app(ConversationAgent::class)->onInbound($this->conversation->fresh(), $inbound));
    };
    ($this->in)(fn () => $this->conversation->forceFill(['ai_mode' => 'off'])->save());
    $send('Hola');
    ($this->in)(fn () => Conversation::query()->whereKey($this->conversation->id)->update(['ai_mode' => 'auto']));
    ($this->in)(fn () => AgentSettings::query()->whereKey($this->ws->id)->update(['enabled' => false]));
    $send('Hola');
    // Un mensaje saliente (el del propio agente) nunca dispara un turno.
    ($this->in)(fn () => AgentSettings::query()->whereKey($this->ws->id)->update(['enabled' => true]));
    $own = agentInbound($this, 'Te escribo yo');
    ($this->in)(fn () => $own->forceFill(['direction' => 'out'])->save());
    ($this->in)(fn () => app(ConversationAgent::class)->onInbound($this->conversation->fresh(), $own));
    expect($ai->requests)->toBe([])->and(($this->in)(fn () => AgentTurn::query()->count()))->toBe(0);
});

it('la configuración la cambia owner o admin; la revisión la ve quien usa la bandeja; nada se cruza entre espacios de trabajo', function () {
    $api = "/api/w/{$this->ws->publicId()}";
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent, true);
    $payload = ['enabled' => true, 'default_mode' => 'assist', 'scope' => 'pinturas y ferretería', 'tone' => 'cálido', 'policies' => ['shipping' => 'Gratis desde $150.000'],
        'handoff_rules' => ['keywords' => ['asesor'], 'intents' => ['complaint'], 'hours' => ['mode' => 'out_of_hours', 'days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00']],
        'discount_rules' => ['enabled' => true, 'coupon_code' => null], 'max_steps' => 5];
    $this->actingAs($agent)->putJson("{$api}/agent/settings", $payload)->assertForbidden();
    $this->actingAs($this->owner)->putJson("{$api}/agent/settings", $payload)->assertUnprocessable()->assertJsonValidationErrors(['discount_rules.coupon_code', 'discount_rules.description']);
    $payload['discount_rules'] = ['enabled' => true, 'coupon_code' => 'WA10', 'description' => '10% por WhatsApp', 'min_subtotal' => 100000];
    $this->actingAs($this->owner)->putJson("{$api}/agent/settings", $payload)->assertOk()->assertJsonPath('settings.default_mode', 'assist')
        ->assertJsonPath('settings.handoff_rules.hours.mode', 'out_of_hours');
    expect(DB::table('audit_logs')->where('action', 'agent.settings_updated')->exists())->toBeTrue();

    FakeAiGateway::install()->callTool('get_product', ['product_id' => '88', 'search' => null])->reply('Está en $141.000.');
    $sandbox = $this->actingAs($this->owner)->postJson("{$api}/agent/sandbox", ['messages' => [['role' => 'user', 'content' => '¿Precio de la pintura?']]])->assertOk();
    expect($sandbox->json('reply'))->toBe('Está en $141.000.')->and($sandbox->json('tool_calls.0.name'))->toBe('get_product');

    $review = $this->actingAs($agent)->getJson("{$api}/agent/review?filter=test")->assertOk();
    expect($review->json('data'))->toHaveCount(1)->and($review->json('data.0.is_test'))->toBeTrue();
    $id = $review->json('data.0.id');
    $this->actingAs($agent)->putJson("{$api}/agent/turns/{$id}/review", ['review_status' => 'wrong'])->assertOk()->assertJsonPath('flagged', true);
    $this->actingAs($agent)->getJson("{$api}/agent/turns/{$id}")->assertOk()->assertJsonPath('suggestion', 'Está en $141.000.');

    $other = $this->workspaceFor($this->userWith2fa());
    $this->actingAs($this->owner)->getJson("/api/w/{$other->publicId()}/agent/review")->assertNotFound();
    expect(app(CurrentWorkspace::class)->run($other, fn () => AgentTurn::query()->count()))->toBe(0);
});

it('la evaluación con el modelo real se corre con el doble y falla si una respuesta inventa', function () {
    $fake = FakeAiGateway::install();
    foreach (AgentEvalCommand::QUESTIONS as $q) {
        match ($q['kind']) {
            'price', 'stock' => $fake->callTool('get_product', ['product_id' => '88', 'search' => null])->reply('La Pintura Azul Galón está en $141.000 y hay 12 unidades.'),
            'cart' => $fake->callTool('create_cart_link', ['items' => [['product_id' => '88', 'variation_id' => null, 'qty' => 2]], 'apply_discount' => false])->reply('Son $282.000: https://tienda.co/?igr_cart=z'),
            'order' => $fake->reply('No encontré ese pedido con el número de este chat.'),
            'policy' => $fake->reply('Envío gratis desde $150.000; a Bogotá llega en 2 días hábiles.'),
            'scope' => $fake->reply('Solo puedo ayudarte con la tienda.'),
        };
    }
    $this->artisan('igr:agent:eval', ['--workspace' => $this->ws->publicId()])->expectsOutputToContain('no inventó precios ni stock')->assertSuccessful();

    $fake = FakeAiGateway::install()->reply('Cuesta $77.000.')->reply('Cuesta $77.000.');
    $this->artisan('igr:agent:eval', ['--workspace' => $this->ws->publicId()])->assertFailed();
});
