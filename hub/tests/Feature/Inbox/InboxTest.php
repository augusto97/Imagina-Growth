<?php

use App\Domain\Inbox\Actions\InboxPresence;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Models\ConversationNote;
use App\Domain\Inbox\Push\FakePushNotifier;
use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Data\RenderedMessage;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

/** El agente de IA de prueba: guarda las llamadas y sugiere un texto fijo. */
final class InboxAgentDouble implements ConversationAgent
{
    /** @var list<string> */
    public array $inbound = [];

    public function onInbound(Conversation $conversation, Message $inbound): void
    {
        $this->inbound[] = $inbound->id;
    }

    public function suggest(Conversation $conversation): ?string
    {
        return 'Claro, ya te comparto el precio.';
    }
}

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    [$this->site] = $this->connectedSite($this->ws);
    $this->push = FakePushNotifier::install();
    $this->agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->api = "/api/w/{$this->ws->publicId()}/inbox";
    Http::fake([
        'graph.test/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(6)]]]),
        'tienda.co/wp-json/*' => function (HttpRequest $r) {
            $body = json_decode($r->body(), true);

            return Http::response(['ok' => true, 'data' => match ($body['command']) {
                'product.lookup' => ['products' => [['id' => '88', 'name' => 'Pintura Azul Galón', 'price' => '141000.00', 'sale_price' => '', 'stock_status' => 'instock', 'stock_qty' => 12, 'url' => 'https://tienda.co/p/azul']]],
                'order.create_payment_link' => ['order_id' => '991', 'url' => 'https://tienda.co/checkout/order-pay/991/?key=wc_order_x'],
                'coupon.create' => ['coupon_id' => 55, 'code' => $body['args']['code']],
                default => [],
            }]);
        },
    ]);
});

function inboxInbound($test, string $text, string $id = 'wamid.IN1')
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111'],
        'contacts' => [['wa_id' => '573001234567', 'profile' => ['name' => 'Ana']]],
        'messages' => [['from' => '573001234567', 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'),
    ], $body);
}

function inboxConversation($test): Conversation
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Conversation::query()->where('contact_id', $test->contact->id)->firstOrFail());
}

it('un entrante abre el ciclo, cuenta no leídos, clasifica, asigna al disponible, avisa en tiempo real y por push', function () {
    Event::fake([InboxUpdated::class]);
    app(InboxPresence::class)->heartbeat($this->ws->id, $this->agent->id, true);

    inboxInbound($this, '¿Cuánto cuesta la pintura azul?')->assertOk();
    $c = inboxConversation($this);
    expect($c->unread_count)->toBe(1)->and($c->status)->toBe('open')->and($c->intent)->toBe('purchase')
        ->and($c->assignee_id)->toBe($this->agent->id)->and($c->opened_at)->not->toBeNull()->and($c->awaiting_since)->not->toBeNull()
        ->and(ConversationEvent::withoutWorkspaceScope()->where('conversation_id', $c->id)->pluck('kind')->all())->toBe(['opened', 'assigned']);
    expect($this->push->sent)->not->toBeEmpty()->and(end($this->push->sent)['user'])->toBe($this->agent->id)
        ->and(end($this->push->sent)['title'])->not->toContain('pintura');
    Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $e) => $e->reason === 'inbound' && $e->broadcastOn()[0]->name === 'private-workspace.'.$this->ws->publicId().'.inbox'
        && ! str_contains(json_encode($e->broadcastWith()), 'pintura'));

    inboxInbound($this, 'Hola?', 'wamid.IN2')->assertOk();
    expect(inboxConversation($this)->unread_count)->toBe(2);
});

it('el agente ve solo lo suyo; dueña y marketing ven todo; nadie ve lo de otro espacio de trabajo', function () {
    inboxInbound($this, 'Hola')->assertOk();
    $c = inboxConversation($this);
    $this->actingAs($this->owner)->getJson("{$this->api}/conversations")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->agent)->getJson("{$this->api}/conversations")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->agent)->getJson("{$this->api}/conversations/{$c->publicId()}")->assertNotFound();
    $this->actingAs($this->agent)->postJson("{$this->api}/conversations/{$c->publicId()}/assign", ['user_id' => $this->agent->publicId()])->assertNotFound();

    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/assign", ['user_id' => $this->agent->publicId()])->assertOk()
        ->assertJsonPath('assignee.id', $this->agent->publicId());
    $this->actingAs($this->agent)->getJson("{$this->api}/conversations?view=mine")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.contact.name', 'Ana')->assertJsonPath('counts.mine', 1);
    $detail = $this->actingAs($this->agent)->getJson("{$this->api}/conversations/{$c->publicId()}")->assertOk();
    expect($detail->json('messages.0.text'))->toBe('Hola')->and($detail->json('window_open'))->toBeTrue();
    // El agente no reasigna a otros.
    $this->actingAs($this->agent)->postJson("{$this->api}/conversations/{$c->publicId()}/assign", ['user_id' => $this->owner->publicId()])->assertForbidden();

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->api}/conversations")->assertForbidden();
    $otherOwner = $this->userWith2fa();
    $other = $this->workspaceFor($otherOwner, 'Otra');
    $this->actingAs($otherOwner)->getJson("/api/w/{$other->publicId()}/inbox/conversations/{$c->publicId()}")->assertNotFound();
    $this->actingAs($otherOwner)->getJson("/api/w/{$other->publicId()}/inbox/conversations?status=all")->assertOk()->assertJsonCount(0, 'data');
});

it('responde dentro de la ventana de 24 h una sola vez por clic; afuera solo con plantilla aprobada', function () {
    inboxInbound($this, '¿Hacen envíos a Pereira?')->assertOk();
    $c = inboxConversation($this);
    $this->travel(3)->minutes();
    $clientId = (string) Str::uuid();
    $sent = $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/messages", ['type' => 'text', 'client_id' => $clientId, 'text' => 'Sí, llegamos en 2 días.'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/messages", ['type' => 'text', 'client_id' => $clientId, 'text' => 'Sí, llegamos en 2 días.'])->assertOk()
        ->assertJsonPath('message_id', $sent->json('message_id'));
    $c->refresh();
    $msg = Message::withoutWorkspaceScope()->where('direction', 'out')->firstOrFail();
    expect($msg->conversation_id)->toBe($c->id)->and($msg->source_type)->toBe('inbox')->and($msg->kind->value)->toBe('service')
        ->and($c->unread_count)->toBe(0)->and($c->first_response_seconds)->toBe(180)
        ->and(ConversationEvent::withoutWorkspaceScope()->where('kind', 'message')->value('actor'))->toBe($this->owner->id);
    expect(Message::withoutWorkspaceScope()->where('direction', 'out')->count())->toBe(1);

    $this->travel(25)->hours();
    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/messages", ['type' => 'text', 'client_id' => (string) Str::uuid(), 'text' => '¿Seguimos?'])
        ->assertUnprocessable()->assertJsonPath('reason', 'outside_24h_window');

    app()->instance(TemplateRenderer::class, new class implements TemplateRenderer
    {
        public function render(string $templateId, RenderContext $context): RenderedMessage
        {
            return new RenderedMessage(waTemplateName: 'seguimiento', waLanguage: 'es', waComponents: [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => (string) ($context->vars['bandeja']['1'] ?? '')]]]]);
        }
    });
    $tpl = app(CurrentWorkspace::class)->run($this->ws, fn () => Template::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'name' => 'Seguimiento', 'category' => 'utility', 'status' => 'ready', 'variables' => ['body' => ['1' => '{{ bandeja.1 }}']]]));
    $this->actingAs($this->owner)->getJson("{$this->api}/templates")->assertOk()->assertJsonPath('data.0.variables', ['1']);
    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/messages", ['type' => 'template', 'client_id' => (string) Str::uuid(), 'template_id' => $tpl->publicId(), 'variables' => ['1' => 'tu pedido']])
        ->assertCreated();
    $out = Message::withoutWorkspaceScope()->where('direction', 'out')->latest('created_at')->first();
    expect($out->kind->value)->toBe('utility')->and($out->payload['wa']['components'][0]['parameters'][0]['text'])->toBe('tu pedido');
});

it('vende desde el chat: producto con precio y stock en vivo, carrito restaurable, enlace de pago y cupón', function () {
    inboxInbound($this, 'Quiero la pintura azul')->assertOk();
    $c = inboxConversation($this);
    $send = fn (array $body) => $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/messages", $body + ['client_id' => (string) Str::uuid()]);

    expect($send(['type' => 'product', 'product_id' => '88'])->assertCreated()->json('text'))->toContain('Pintura Azul Galón')->toContain('$141.000')->toContain('Disponible');
    expect($send(['type' => 'cart_link', 'items' => [['product_id' => '88', 'qty' => 2]]])->assertCreated()->json('text'))->toContain('https://tienda.co/?igr_cart=');
    expect($send(['type' => 'payment_link', 'items' => [['product_id' => '88', 'qty' => 1]]])->assertCreated()->json('text'))->toContain('order-pay/991');
    $coupon = $send(['type' => 'coupon', 'kind' => 'percent', 'amount' => 10])->assertCreated()->json('text');
    expect($coupon)->toMatch('/CHAT-[A-Z0-9]+/')
        ->and(DB::table('coupons_issued')->where('source_type', 'inbox')->where('contact_id', $this->contact->id)->exists())->toBeTrue();
    $send(['type' => 'coupon', 'kind' => 'percent', 'amount' => 90])->assertUnprocessable();
    $send(['type' => 'product', 'product_id' => 'abc'])->assertUnprocessable();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->body(), '"product.lookup"'));
});

it('notas internas cifradas, etiquetas, estados con resolución y respuestas rápidas solo para quien administra', function () {
    inboxInbound($this, 'Hola')->assertOk();
    $c = inboxConversation($this);
    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/notes", ['body' => 'Cliente mayorista, ofrecer precio especial'])->assertCreated();
    expect((string) DB::table('conversation_notes')->value('body'))->not->toContain('mayorista')
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => ConversationNote::query()->first()->body))->toBe('Cliente mayorista, ofrecer precio especial');

    $this->actingAs($this->owner)->patchJson("{$this->api}/conversations/{$c->publicId()}", ['labels' => ['mayorista', ' vip ', 'vip']])->assertOk()->assertJsonPath('labels', ['mayorista', 'vip']);
    $this->travel(10)->minutes();
    $this->actingAs($this->owner)->patchJson("{$this->api}/conversations/{$c->publicId()}", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
    $closed = ConversationEvent::withoutWorkspaceScope()->where('kind', 'status')->first();
    expect($closed->data['resolution_seconds'])->toBe(600);
    $this->actingAs($this->owner)->getJson("{$this->api}/conversations?label=mayorista&status=closed")->assertJsonCount(1, 'data');

    $this->actingAs($this->agent)->postJson("{$this->api}/quick-replies", ['shortcut' => 'envio', 'title' => 'Envíos', 'body' => 'Enviamos a todo el país.'])->assertForbidden();
    $r = $this->actingAs($this->owner)->postJson("{$this->api}/quick-replies", ['shortcut' => 'envio', 'title' => 'Envíos', 'body' => 'Enviamos a todo el país.'])->assertCreated();
    $this->actingAs($this->owner)->postJson("{$this->api}/quick-replies", ['shortcut' => 'envio', 'title' => 'Otra', 'body' => 'x'])->assertUnprocessable();
    $this->actingAs($this->agent)->getJson("{$this->api}/quick-replies")->assertOk()->assertJsonPath('data.0.shortcut', 'envio');
    $this->actingAs($this->owner)->putJson("{$this->api}/quick-replies/{$r->json('id')}", ['shortcut' => 'envios', 'title' => 'Envíos', 'body' => 'Todo el país.'])->assertOk();
    $this->actingAs($this->owner)->deleteJson("{$this->api}/quick-replies/{$r->json('id')}")->assertOk();
});

it('con la IA encendida el entrante va al agente; la sugerencia y el traspaso a un humano', function () {
    $double = new InboxAgentDouble;
    app()->instance(ConversationAgent::class, $double);
    DB::table('ai_agent_settings')->insert(['workspace_id' => $this->ws->id, 'enabled' => true, 'default_mode' => 'auto', 'created_at' => now(), 'updated_at' => now()]);

    inboxInbound($this, '¿Tienen la pintura azul?')->assertOk();
    $c = inboxConversation($this);
    expect($c->ai_mode)->toBe('auto')->and($double->inbound)->toHaveCount(1);
    // Una baja nunca va al agente.
    inboxInbound($this, 'BAJA', 'wamid.IN2')->assertOk();
    expect($double->inbound)->toHaveCount(1);

    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/suggest")->assertOk()->assertJsonPath('suggestion', 'Claro, ya te comparto el precio.');
    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/handoff", ['reason' => 'pide_humano'])->assertOk()
        ->assertJsonPath('ai_mode', 'off')->assertJsonPath('handoff_reason', 'pide_humano');
    inboxInbound($this, 'Hola de nuevo', 'wamid.IN3')->assertOk();
    expect($double->inbound)->toHaveCount(1);
    $this->actingAs($this->owner)->patchJson("{$this->api}/conversations/{$c->publicId()}", ['ai_mode' => 'assist'])->assertOk()->assertJsonPath('ai_mode', 'assist');
});

it('métricas: primera respuesta, resolución, mensajes por agente y ventas atribuidas a la bandeja', function () {
    inboxInbound($this, 'Hola')->assertOk();
    $c = inboxConversation($this);
    $this->actingAs($this->owner)->postJson("{$this->api}/conversations/{$c->publicId()}/assign", ['user_id' => $this->agent->publicId()])->assertOk();
    $this->travel(2)->minutes();
    $id = $this->actingAs($this->agent)->postJson("{$this->api}/conversations/{$c->publicId()}/messages", ['type' => 'text', 'client_id' => (string) Str::uuid(), 'text' => 'Hola, ¿en qué te ayudo?'])->assertCreated()->json('message_id');
    $this->travel(4)->minutes();
    $this->actingAs($this->agent)->patchJson("{$this->api}/conversations/{$c->publicId()}", ['status' => 'closed'])->assertOk();
    DB::table('orders')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => '5001', 'number' => '5001',
        'contact_id' => $this->contact->id, 'status' => 'processing', 'is_paid' => true, 'currency' => 'COP', 'subtotal' => '250000.00', 'total' => '250000.00', 'total_ws' => '250000.00',
        'attributed_message_id' => substr((string) $id, 4), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner)->getJson("{$this->api}/metrics")->assertOk()->assertJsonPath('opened', 1);
    $m = $this->actingAs($this->owner)->getJson("{$this->api}/metrics?days=7")->assertOk();
    $agent = collect($m->json('agents'))->firstWhere('id', $this->agent->publicId());
    expect($m->json('opened'))->toBe(1)->and($m->json('first_response.avg'))->toBe(120)->and($m->json('resolution.median'))->toBe(360)
        ->and($m->json('sales.orders'))->toBe(1)->and($m->json('sales.revenue'))->toBe('250000.00')
        ->and($agent['messages'])->toBe(1)->and($agent['resolved'])->toBe(1)->and($agent['orders'])->toBe(1)->and($agent['first_response']['avg'])->toBe(120);
});

it('ajustes: reglas de asignación por intención y suscripción push del navegador', function () {
    $this->actingAs($this->agent)->putJson("{$this->api}/settings", ['auto_assign' => true, 'rules' => []])->assertForbidden();
    $this->actingAs($this->owner)->putJson("{$this->api}/settings", ['auto_assign' => true, 'rules' => [['intent' => 'complaint', 'user' => $this->agent->publicId()]]])->assertOk()
        ->assertJsonPath('rules.0.intent', 'complaint');
    $this->actingAs($this->owner)->putJson("{$this->api}/settings", ['auto_assign' => true, 'rules' => [['intent' => 'complaint', 'user' => 'usr_01JD3QG7Y2V8K6N4M5P9R0S1T2']]])->assertUnprocessable();

    inboxInbound($this, 'Pésimo servicio, mi pedido nunca llegó')->assertOk();
    expect(inboxConversation($this)->assignee_id)->toBe($this->agent->id);

    $this->actingAs($this->agent)->postJson("{$this->api}/push/subscriptions", ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth1']])->assertCreated();
    expect((string) DB::table('push_subscriptions')->value('endpoint'))->not->toContain('push.example.com');
    $this->actingAs($this->agent)->deleteJson("{$this->api}/push/subscriptions", ['endpoint' => 'https://push.example.com/abc'])->assertOk();
    expect(DB::table('push_subscriptions')->count())->toBe(0);
});
