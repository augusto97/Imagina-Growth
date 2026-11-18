<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * Messenger e Instagram Direct en el hub (M11, Fase 5): webhooks firmados de la
 * app de Meta, contactos por PSID/IGSID de la página, ventana de 24 h, la etiqueta
 * HUMAN_AGENT de Messenger solo para personas y nunca marketing.
 */

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    [$this->messenger, $this->instagram] = ($this->in)(fn () => [
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'messenger', 'provider' => 'meta_messenger', 'display_name' => 'Pinturas La 14',
            'status' => 'active', 'is_default' => true, 'external_id' => '5551', 'identity' => 'Pinturas La 14',
            'config' => ['page_id' => '5551', 'page_access_token' => 'EAAP-token-de-la-pagina']]),
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'instagram', 'provider' => 'meta_instagram', 'display_name' => '@pinturasla14',
            'status' => 'active', 'is_default' => true, 'external_id' => '1784', 'identity' => '@pinturasla14',
            'config' => ['page_id' => '5551', 'ig_user_id' => '1784', 'page_access_token' => 'EAAP-token-de-la-pagina']]),
    ]);
    $this->graph = [];
    Http::fake(['graph.test/*' => fn (HttpRequest $r) => ($this->graphHandler)($r)]);
    $this->graphHandler = function (HttpRequest $r) {
        $this->graph[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->method() === 'POST' ? json_decode($r->body(), true) : null];
        $path = (string) parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/messages') => Http::response(['recipient_id' => '1', 'message_id' => 'm_OUT'.count($this->graph)]),
            str_ends_with($path, '/subscribed_apps') => Http::response(['success' => true]),
            str_ends_with($path, '/9001') => Http::response(['first_name' => 'Luisa', 'last_name' => 'Gómez', 'id' => '9001']),
            str_ends_with($path, '/7001') => Http::response(['name' => 'Camila Ríos', 'username' => 'cami.rios', 'id' => '7001']),
            default => Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400),
        };
    };
});

function socialPost($test, array $payload, ?string $secret = 'meta-app-secret')
{
    $body = json_encode($payload);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
    ], $body);
}

/** Un evento de `entry[].messaging[]` de Messenger (`page`) o Instagram (`instagram`). */
function socialEvent(string $sender, array $event, string $owner = '5551', string $object = 'page'): array
{
    return ['object' => $object, 'entry' => [['id' => $owner, 'time' => now()->getTimestampMs(), 'messaging' => [
        ['sender' => ['id' => $sender], 'recipient' => ['id' => $owner], 'timestamp' => now()->getTimestampMs()] + $event,
    ]]]];
}

function socialText(string $sender, string $text, string $mid, string $owner = '5551', string $object = 'page'): array
{
    return socialEvent($sender, ['message' => ['mid' => $mid, 'text' => $text]], $owner, $object);
}

function socialDispatch($test, ChannelAccount $account, string $contactId, MessageKind $kind = MessageKind::Service, bool $human = false, string $key = 'k1')
{
    return ($test->in)(fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $contactId, $account->channel, $kind, 'inbox', context: ['text' => 'Hola, ¿te ayudo con algo más?'],
        idempotencyKey: 'social:'.$key, channelAccountId: $account->id, humanAgent: $human,
    )));
}

it('Messenger: crea el contacto por PSID de la página, abre la ventana, pide el nombre y avisa a la bandeja', function () {
    Event::fake([InboundMessageReceived::class]);
    socialPost($this, socialText('9001', '¿Tienen pintura azul?', 'm_IN1'))->assertOk();

    [$contact, $conversation, $message, $identity] = ($this->in)(fn () => [
        Contact::query()->where('id', '!=', $this->contact->id)->firstOrFail(),
        Conversation::query()->where('channel_account_id', $this->messenger->id)->firstOrFail(),
        Message::query()->where('provider_message_id', 'm_IN1')->firstOrFail(),
        ContactIdentity::query()->where('type', 'messenger_psid')->firstOrFail(),
    ]);
    expect($identity->value)->toBe('5551:9001')->and($identity->contact_id)->toBe($contact->id)
        ->and($contact->first_name)->toBe('Luisa')->and($contact->last_name)->toBe('Gómez')->and($contact->phone)->toBeNull()
        ->and($message->channel)->toBe(Channel::Messenger)->and($message->provider)->toBe('meta_messenger')
        ->and($message->payload['text'])->toBe('¿Tienen pintura azul?')->and($message->conversation_id)->toBe($conversation->id)
        ->and($conversation->window_expires_at->equalTo(now()->addDay()))->toBeTrue();
    Event::assertDispatched(InboundMessageReceived::class, fn (InboundMessageReceived $e) => $e->messageId === $message->id && $e->contactId === $contact->id);

    // El mismo PSID vuelve: mismo contacto y conversación; Meta reintenta el webhook: no se duplica.
    socialPost($this, socialText('9001', 'Galón', 'm_IN2'))->assertOk();
    socialPost($this, socialText('9001', 'Galón', 'm_IN2'))->assertOk();
    expect(($this->in)(fn () => [Contact::query()->count(), Conversation::query()->count(), Message::query()->where('direction', 'in')->count()]))->toBe([2, 1, 2]);
});

it('ignora los ecos de la página y aplica entregado y leído (Messenger por marca de tiempo)', function () {
    socialPost($this, socialText('9001', 'Hola', 'm_IN1'))->assertOk();
    $contactId = ($this->in)(fn () => ContactIdentity::query()->where('type', 'messenger_psid')->value('contact_id'));
    socialDispatch($this, $this->messenger, $contactId);
    $out = ($this->in)(fn () => Message::query()->where('direction', 'out')->firstOrFail());
    expect($out->status)->toBe('sent');

    socialPost($this, socialEvent('5551', ['message' => ['mid' => 'm_ECHO', 'text' => 'Hola, ¿te ayudo con algo más?', 'is_echo' => true]], '5551'))->assertOk();
    expect(($this->in)(fn () => Message::query()->where('provider_message_id', 'm_ECHO')->exists()))->toBeFalse();

    $this->travel(1)->minutes();
    socialPost($this, socialEvent('9001', ['delivery' => ['mids' => [$out->provider_message_id], 'watermark' => now()->getTimestampMs()]]))->assertOk();
    socialPost($this, socialEvent('9001', ['read' => ['watermark' => now()->getTimestampMs()]]))->assertOk();
    $out->refresh();
    expect($out->delivered_at)->not->toBeNull()->and($out->read_at)->not->toBeNull()->and($out->status)->toBe('read');
});

it('Instagram: respuestas a historias, menciones, botones y adjuntos; el IGSID es de la cuenta, no del cliente', function () {
    socialPost($this, socialEvent('7001', ['message' => ['mid' => 'ig_1', 'text' => '¡Me encanta ese color!', 'reply_to' => ['story' => ['url' => 'https://cdn.test/s', 'id' => '1799']]]], '1784', 'instagram'))->assertOk();
    socialPost($this, socialEvent('7001', ['message' => ['mid' => 'ig_2', 'attachments' => [['type' => 'story_mention', 'payload' => ['url' => 'https://cdn.test/m']]]]], '1784', 'instagram'))->assertOk();
    socialPost($this, socialEvent('7001', ['postback' => ['mid' => 'ig_3', 'title' => 'Ver precios', 'payload' => 'PRECIOS']], '1784', 'instagram'))->assertOk();
    socialPost($this, socialEvent('7001', ['message' => ['mid' => 'ig_4', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.test/foto.jpg']]]]], '1784', 'instagram'))->assertOk();

    $rows = ($this->in)(fn () => Message::query()->where('direction', 'in')->orderBy('provider_message_id')->get());
    expect($rows->map(fn (Message $m) => $m->payload['type'])->all())->toBe(['story_reply', 'story_mention', 'button', 'image'])
        ->and($rows[0]->payload['referral_source'])->toBe('story')->and($rows[0]->payload['referral_id'])->toBe('1799')
        ->and($rows[2]->payload['button_payload'])->toBe('PRECIOS')->and($rows[2]->payload['text'])->toBe('Ver precios')
        ->and($rows[3]->payload['media']['url'])->toBe('https://cdn.test/foto.jpg')
        ->and($rows->pluck('channel')->unique()->all())->toBe([Channel::Instagram]);
    $contact = ($this->in)(fn () => Contact::query()->find(ContactIdentity::query()->where('type', 'instagram_igsid')->value('contact_id')));
    expect($contact->first_name)->toBe('Camila')->and($contact->last_name)->toBe('Ríos');

    // El mismo número de remitente en Messenger es otra persona (los IDs son por página o cuenta).
    socialPost($this, socialText('7001', 'Hola', 'm_X'))->assertOk();
    expect(($this->in)(fn () => ContactIdentity::query()->whereIn('type', ['messenger_psid', 'instagram_igsid'])->distinct()->count('contact_id')))->toBe(2);
});

it('rechaza webhooks sin firma válida y los de una página que no está conectada', function () {
    socialPost($this, socialText('9001', 'Hola', 'm_IN1'), 'otro-secreto')->assertForbidden();
    socialPost($this, socialText('9001', 'Hola', 'm_IN2', '8888'))->assertOk();
    expect(Message::withoutWorkspaceScope()->where('direction', 'in')->count())->toBe(0);
});

it('responde dentro de la ventana por la Send API con RESPONSE y el PSID de la página', function () {
    socialPost($this, socialText('9001', 'Hola', 'm_IN1'))->assertOk();
    $contactId = ($this->in)(fn () => ContactIdentity::query()->where('type', 'messenger_psid')->value('contact_id'));
    expect(socialDispatch($this, $this->messenger, $contactId)->status)->toBe('queued');

    $send = collect($this->graph)->first(fn ($c) => str_ends_with((string) parse_url($c['url'], PHP_URL_PATH), '/5551/messages'));
    expect($send['body'])->toBe(['recipient' => ['id' => '9001'], 'messaging_type' => 'RESPONSE', 'message' => ['text' => 'Hola, ¿te ayudo con algo más?']]);
    $out = ($this->in)(fn () => Message::query()->where('direction', 'out')->firstOrFail());
    expect($out->status)->toBe('sent')->and($out->provider_message_id)->toStartWith('m_OUT')->and((float) $out->cost_estimated)->toBe(0.0);
});

it('nunca envía marketing por Messenger ni Instagram, aunque haya consentimiento', function () {
    socialPost($this, socialText('9001', 'Hola', 'm_IN1'))->assertOk();
    $contact = ($this->in)(fn () => Contact::query()->find(ContactIdentity::query()->where('type', 'messenger_psid')->value('contact_id')));
    $this->grant($contact, 'messenger');
    $result = socialDispatch($this, $this->messenger, $contact->id, MessageKind::Marketing);
    expect($result->status)->toBe('denied')->and($result->reason)->toBe('channel_no_marketing');
});

it('Messenger fuera de 24 h: la IA no responde; una persona sí, con HUMAN_AGENT, hasta 7 días', function () {
    socialPost($this, socialText('9001', 'Hola', 'm_IN1'))->assertOk();
    $contactId = ($this->in)(fn () => ContactIdentity::query()->where('type', 'messenger_psid')->value('contact_id'));
    $this->travel(30)->hours();

    $ai = socialDispatch($this, $this->messenger, $contactId, human: false, key: 'ai');
    expect($ai->status)->toBe('denied')->and($ai->reason)->toBe('outside_24h_window');

    expect(socialDispatch($this, $this->messenger, $contactId, human: true, key: 'human')->status)->toBe('queued');
    $send = collect($this->graph)->last(fn ($c) => str_ends_with((string) parse_url($c['url'], PHP_URL_PATH), '/messages'));
    expect($send['body']['messaging_type'])->toBe('MESSAGE_TAG')->and($send['body']['tag'])->toBe('HUMAN_AGENT');

    $this->travel(6)->days();
    $late = socialDispatch($this, $this->messenger, $contactId, human: true, key: 'late');
    expect($late->status)->toBe('denied')->and($late->reason)->toBe('outside_human_agent_window');
});

it('Instagram fuera de 24 h: ventana cerrada para todos (sin etiquetas)', function () {
    socialPost($this, socialText('7001', 'Hola', 'ig_1', '1784', 'instagram'))->assertOk();
    $contactId = ($this->in)(fn () => ContactIdentity::query()->where('type', 'instagram_igsid')->value('contact_id'));
    expect(socialDispatch($this, $this->instagram, $contactId, key: 'in')->status)->toBe('queued');
    $this->travel(25)->hours();
    $human = socialDispatch($this, $this->instagram, $contactId, human: true, key: 'out');
    expect($human->status)->toBe('denied')->and($human->reason)->toBe('outside_24h_window');
    // Un contacto que nunca le escribió a la cuenta no tiene dirección en el canal.
    $none = socialDispatch($this, $this->instagram, $this->contact->id, key: 'none');
    expect($none->status)->toBe('denied')->and($none->reason)->toBe('no_address');
});

it('conecta una página de Messenger y una cuenta de Instagram comprobándolas con Meta; valida y respeta los roles', function () {
    $api = "/api/w/{$this->ws->publicId()}/channels";
    ($this->in)(fn () => ChannelAccount::query()->whereIn('provider', ['meta_messenger', 'meta_instagram'])->update(['status' => 'disconnected', 'is_default' => false]));
    $this->graphHandler = function (HttpRequest $r) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $token = $r->header('Authorization')[0] ?? '';

        return match (true) {
            ! str_contains($token, 'EAAP-bueno') => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400),
            str_ends_with($path, '/subscribed_apps') => Http::response(['success' => true]),
            str_ends_with($path, '/600123') => Http::response(['id' => '600123', 'name' => 'Pinturas La 14', 'instagram_business_account' => ['id' => '179012', 'username' => 'pinturasla14']]),
            default => Http::response(['error' => ['message' => 'No existe', 'code' => 100]], 400),
        };
    };

    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->postJson("{$api}/messenger", ['page_id' => '600123', 'page_access_token' => 'EAAP-bueno-0123456789'])->assertForbidden();
    $this->actingAs($this->owner)->postJson("{$api}/messenger", ['page_id' => 'abc', 'page_access_token' => 'EAAP-bueno-0123456789'])->assertUnprocessable()->assertJsonValidationErrors('page_id');
    $this->actingAs($this->owner)->postJson("{$api}/messenger", ['page_id' => '600123', 'page_access_token' => 'EAAP-malo-0123456789xx'])->assertUnprocessable()->assertJsonValidationErrors('page_access_token');

    $res = $this->actingAs($this->owner)->postJson("{$api}/messenger", ['page_id' => '600123', 'page_access_token' => 'EAAP-bueno-0123456789'])->assertCreated()
        ->assertJsonPath('data.channel', 'messenger')->assertJsonPath('data.status', 'active')->assertJsonPath('data.identity', 'Pinturas La 14')
        ->assertJsonPath('data.webhook_subscription.object', 'page')->assertJsonPath('data.capabilities.marketing', false)->assertJsonPath('data.is_default', true);
    expect($res->getContent())->not->toContain('EAAP-bueno');
    $this->actingAs($this->owner)->postJson("{$api}/instagram", ['page_id' => '600123', 'ig_user_id' => '1111111', 'page_access_token' => 'EAAP-bueno-0123456789'])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson("{$api}/instagram", ['page_id' => '600123', 'ig_user_id' => '179012', 'page_access_token' => 'EAAP-bueno-0123456789'])->assertCreated()
        ->assertJsonPath('data.identity', '@pinturasla14')->assertJsonPath('data.webhook_subscription.object', 'instagram');
    // Estado de la conexión: se vuelve a comprobar con Meta.
    $this->actingAs($this->owner)->postJson("{$api}/{$res->json('data.id')}/sync")->assertOk()->assertJsonPath('data.last_error', null);

    // La misma página no se conecta en otro espacio de trabajo.
    $otherOwner = $this->userWith2fa();
    $other = $this->workspaceFor($otherOwner, 'Otra tienda');
    $this->actingAs($otherOwner)->postJson("/api/w/{$other->publicId()}/channels/messenger", ['page_id' => '600123', 'page_access_token' => 'EAAP-bueno-0123456789'])
        ->assertUnprocessable()->assertJsonValidationErrors('page_id');
});

it('aislamiento: el mensaje de una página llega solo al espacio de trabajo dueño de la página', function () {
    $otherOwner = $this->userWith2fa();
    $other = $this->workspaceFor($otherOwner, 'Otra tienda');
    app(CurrentWorkspace::class)->run($other, fn () => ChannelAccount::query()->create(['workspace_id' => $other->id, 'channel' => 'messenger', 'provider' => 'meta_messenger',
        'display_name' => 'Otra', 'status' => 'active', 'external_id' => '7777', 'config' => ['page_id' => '7777', 'page_access_token' => 'EAAP-otra']]));

    socialPost($this, socialText('9001', 'Hola', 'm_A'))->assertOk();
    socialPost($this, socialText('9001', 'Hola otra', 'm_B', '7777'))->assertOk();
    $mine = ($this->in)(fn () => [Message::query()->where('direction', 'in')->pluck('provider_message_id')->all(), ContactIdentity::query()->where('type', 'messenger_psid')->pluck('value')->all()]);
    $theirs = app(CurrentWorkspace::class)->run($other, fn () => [Message::query()->where('direction', 'in')->pluck('provider_message_id')->all(), ContactIdentity::query()->where('type', 'messenger_psid')->pluck('value')->all()]);
    expect($mine)->toBe([['m_A'], ['5551:9001']])->and($theirs)->toBe([['m_B'], ['7777:9001']]);
    $this->actingAs($otherOwner)->getJson("/api/w/{$other->publicId()}/inbox/conversations")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'messenger');
});
