<?php

use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Agent\Models\AgentTurn;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Templates\Models\Template;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * La bandeja con Messenger e Instagram (M11, Fase 5): canal por conversación,
 * filtro, estado de la ventana por canal, HUMAN_AGENT solo para personas y el
 * agente de ventas con las mismas reglas.
 */

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    [$this->site] = $this->connectedSite($this->ws);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->api = "/api/w/{$this->ws->publicId()}/inbox";
    [$this->messenger, $this->instagram] = ($this->in)(fn () => [
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'messenger', 'provider' => 'meta_messenger', 'display_name' => 'Pinturas La 14',
            'status' => 'active', 'is_default' => true, 'external_id' => '5551', 'config' => ['page_id' => '5551', 'page_access_token' => 'EAAP-token']]),
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'instagram', 'provider' => 'meta_instagram', 'display_name' => '@pinturasla14',
            'status' => 'active', 'is_default' => true, 'external_id' => '1784', 'config' => ['page_id' => '5551', 'ig_user_id' => '1784', 'page_access_token' => 'EAAP-token']]),
    ]);
    $this->sends = [];
    Http::fake([
        'graph.test/*' => function (HttpRequest $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/messages')) {
                $this->sends[] = json_decode($r->body(), true);

                return Http::response(['recipient_id' => '1', 'message_id' => 'm_'.Str::random(8)]);
            }

            return str_ends_with($path, '/9001')
                ? Http::response(['first_name' => 'Luisa', 'last_name' => 'Gómez'])
                : Http::response(['error' => ['message' => 'Sin permiso', 'code' => 10]], 400); // sin la API de perfil de Instagram
        },
        'tienda.co/wp-json/*' => Http::response(['ok' => true, 'data' => ['products' => [
            ['id' => '88', 'name' => 'Pintura Azul Galón', 'price' => '141000.00', 'sale_price' => '', 'stock_status' => 'instock', 'url' => 'https://tienda.co/p/azul'],
        ]]]),
    ]);
});

function socialInboxIn($test, string $sender, string $text, string $mid, string $owner = '5551', string $object = 'page')
{
    $body = json_encode(['object' => $object, 'entry' => [['id' => $owner, 'time' => now()->getTimestampMs(), 'messaging' => [
        ['sender' => ['id' => $sender], 'recipient' => ['id' => $owner], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => $mid, 'text' => $text]],
    ]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'),
    ], $body);
}

function socialConversation($test, ChannelAccount $account): Conversation
{
    return ($test->in)(fn () => Conversation::query()->where('channel_account_id', $account->id)->firstOrFail());
}

function socialSend($test, Conversation $c, array $input)
{
    return $test->actingAs($test->owner)->postJson("{$test->api}/conversations/{$c->publicId()}/messages", $input + ['client_id' => (string) Str::uuid()]);
}

it('lista las conversaciones con su canal, filtra por canal y nombra al cliente sin perfil', function () {
    socialInboxIn($this, '9001', '¿Tienen pintura azul?', 'm_1')->assertOk();
    $this->travel(1)->minutes();
    socialInboxIn($this, '7001', 'Hola', 'ig_1', '1784', 'instagram')->assertOk();

    $all = $this->actingAs($this->owner)->getJson("{$this->api}/conversations")->assertOk()->assertJsonCount(2, 'data');
    expect(collect($all->json('data'))->pluck('channel', 'contact.name')->all())->toBe(['Cliente de Instagram' => 'instagram', 'Luisa Gómez' => 'messenger'])
        ->and($all->json('data.0.window_state'))->toBe('open');
    $this->actingAs($this->owner)->getJson("{$this->api}/conversations?channel=messenger")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'messenger');
    $this->actingAs($this->owner)->getJson("{$this->api}/conversations?channel=whatsapp")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->owner)->getJson("{$this->api}/conversations?channel=telegram")->assertUnprocessable();
});

it('Messenger: responde en la ventana; fuera de ella una persona responde con HUMAN_AGENT; sin plantillas', function () {
    socialInboxIn($this, '9001', '¿Hacen envíos a Pereira?', 'm_1')->assertOk();
    $c = socialConversation($this, $this->messenger);
    socialSend($this, $c, ['type' => 'text', 'text' => 'Sí, llegamos en 2 días.'])->assertCreated();
    expect(end($this->sends))->toMatchArray(['recipient' => ['id' => '9001'], 'messaging_type' => 'RESPONSE']);

    $this->travel(30)->hours();
    $detail = $this->actingAs($this->owner)->getJson("{$this->api}/conversations/{$c->publicId()}")->assertOk();
    expect($detail->json('channel'))->toBe('messenger')->and($detail->json('window_state'))->toBe('human_agent')
        ->and($detail->json('window_open'))->toBeFalse()->and($detail->json('human_agent_until'))->not->toBeNull();
    socialSend($this, $c, ['type' => 'text', 'text' => 'Te escribo para confirmar tu dirección.'])->assertCreated();
    expect(end($this->sends))->toMatchArray(['messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT']);

    $template = ($this->in)(fn () => Template::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'name' => 'Seguimiento', 'category' => 'utility', 'status' => 'ready']));
    socialSend($this, $c, ['type' => 'template', 'template_id' => $template->publicId()])->assertUnprocessable()->assertJsonFragment(['message' => 'Las plantillas solo existen en WhatsApp: en Messenger solo puedes responder mientras la ventana esté abierta.']);

    $this->travel(7)->days();
    socialSend($this, $c, ['type' => 'text', 'text' => '¿Sigues interesada?'])->assertUnprocessable()->assertJsonPath('reason', 'outside_human_agent_window');
    expect(($this->in)(fn () => Message::query()->where('direction', 'out')->where('channel', 'messenger')->count()))->toBe(2);
});

it('Instagram: fuera de la ventana no sale nada; la tarjeta de producto va como texto con enlace', function () {
    socialInboxIn($this, '7001', '¿Precio de la pintura azul?', 'ig_1', '1784', 'instagram')->assertOk();
    $c = socialConversation($this, $this->instagram);
    socialSend($this, $c, ['type' => 'product', 'product_id' => '88'])->assertCreated();
    expect(end($this->sends)['message']['text'])->toContain('Pintura Azul Galón')->toContain('$141.000')->toContain('https://go.test/c/') // enlace rastreado: el pedido queda atribuido
        ->and(end($this->sends))->not->toHaveKey('tag');

    $this->travel(25)->hours();
    $this->actingAs($this->owner)->getJson("{$this->api}/conversations/{$c->publicId()}")->assertOk()->assertJsonPath('window_state', 'closed');
    socialSend($this, $c, ['type' => 'text', 'text' => '¿Te ayudo con algo más?'])->assertUnprocessable()
        ->assertJsonPath('reason', 'outside_24h_window')->assertJsonFragment(['message' => 'Ventana cerrada: pasaron más de 24 horas desde el último mensaje del cliente. En Instagram solo puedes responder cuando vuelva a escribir.']);
});

it('el agente de ventas atiende Messenger con las mismas reglas y nunca usa HUMAN_AGENT', function () {
    socialInboxIn($this, '9001', 'Hola', 'm_0')->assertOk();
    $c = socialConversation($this, $this->messenger);
    ($this->in)(fn () => AgentSettings::query()->create(['workspace_id' => $this->ws->id, 'enabled' => true, 'default_mode' => 'auto', 'max_steps' => 4,
        'policies' => ['shipping' => 'Envío gratis desde $150.000.'], 'handoff_rules' => AgentSettings::defaultHandoff(), 'discount_rules' => ['enabled' => false]]));
    $c->forceFill(['ai_mode' => 'auto'])->save();
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    $dispatcher = FakeMessageDispatcher::install();
    $ai = FakeAiGateway::install()->reply('Sí, el envío es gratis desde $150.000.')->reply('Te comunico con una persona del equipo.');
    $contactId = ($this->in)(fn () => ContactIdentity::query()->where('type', 'messenger_psid')->value('contact_id'));
    $inbound = fn (string $text) => ($this->in)(fn () => Message::query()->create([
        'workspace_id' => $this->ws->id, 'contact_id' => $contactId, 'channel_account_id' => $this->messenger->id, 'channel' => 'messenger', 'provider' => 'meta_messenger',
        'provider_message_id' => 'm_'.uniqid(), 'direction' => 'in', 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
        'payload' => ['type' => 'text', 'text' => $text], 'conversation_id' => $c->id,
    ]));

    $first = $inbound('¿El envío es gratis?');
    ($this->in)(fn () => app(ConversationAgent::class)->onInbound($c->fresh(), $first));
    $turn = ($this->in)(fn () => AgentTurn::query()->where('inbound_message_id', $first->id)->firstOrFail());
    expect($turn->status)->toBe('replied')->and($dispatcher->dispatched)->toHaveCount(1)
        ->and($dispatcher->dispatched[0]->channel)->toBe(Channel::Messenger)->and($dispatcher->dispatched[0]->humanAgent)->toBeFalse()
        ->and($ai->requests[0]['request']->system)->toContain('asesor de ventas por Messenger')->not->toContain('WhatsApp');

    // Pasadas las 24 h, la IA no escribe aunque Messenger permita HUMAN_AGENT: traspasa a una persona.
    $this->travel(30)->hours();
    $late = $inbound('¿Y a Pereira?');
    ($this->in)(fn () => app(ConversationAgent::class)->onInbound($c->fresh(), $late));
    expect(($this->in)(fn () => AgentTurn::query()->where('inbound_message_id', $late->id)->value('status')))->not->toBe('replied')
        ->and($dispatcher->dispatched)->toHaveCount(1);
});
