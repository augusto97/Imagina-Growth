<?php

use App\Domain\Billing\Usage\UsageCalculator;
use App\Domain\Connections\Mail\ConnectionAlertMail;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Inbox\Jobs\RunConversationAgent;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Models\MessageAttachment;
use App\Domain\Inbox\Push\FakePushNotifier;
use App\Domain\Messaging\Coexistence\BusinessAppContacts;
use App\Domain\Messaging\Jobs\ImportBusinessAppHistoryJob;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Jobs\ExtractLeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * Coexistencia de WhatsApp (lote 4): el número sigue en la app de WhatsApp Business del celular y también está en la
 * Cloud API. Los payloads siguen los ejemplos de Meta:
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/smb_message_echoes
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update
 */

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    $this->push = FakePushNotifier::install();
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
});

/** Un cambio del webhook de la WABA 2222 para el número 1111 (display 573000000000), firmado como lo firma Meta. */
function coexWebhook($test, string $field, array $value, string $entryId = '2222')
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => $entryId, 'changes' => [[
        'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '573000000000', 'phone_number_id' => '1111']] + $value,
        'field' => $field,
    ]]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $body, 'meta-app-secret'),
    ], $body);
}

/** @param array<string, mixed> $extra */
function coexEcho($test, string $id, string $text, ?string $to = '573001234567', string $userId = 'CO.1A2B3C', array $extra = [])
{
    return coexWebhook($test, 'smb_message_echoes', [
        'contacts' => [array_filter(['wa_id' => $to, 'user_id' => $userId])],
        'message_echoes' => [array_filter(['from' => '573000000000', 'to' => $to, 'to_user_id' => $userId, 'id' => $id,
            'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $text]]) + $extra],
    ]);
}

function coexInbound($test, string $text, string $id = 'wamid.IN1')
{
    return coexWebhook($test, 'messages', [
        'contacts' => [['wa_id' => '573001234567', 'profile' => ['name' => 'Ana']]],
        'messages' => [['from' => '573001234567', 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $text]]],
    ]);
}

function coexRun($test, Closure $fn): mixed
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

it('lo escrito desde el celular queda como respuesta humana «Desde el celular» y la IA en automático pasa a una persona', function () {
    $dispatcher = FakeMessageDispatcher::install();
    coexInbound($this, 'Hola, ¿tienen pintura azul de galón?')->assertOk();
    $leadId = app(LeadIntake::class)->capture(new LeadIntakeInput($this->ws->id, $this->contact->id, 'manual', 'coex-1'));
    coexRun($this, fn () => Conversation::query()->firstOrFail()->forceFill(['ai_mode' => 'auto'])->save());
    $today = fn () => collect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/leads/today")->assertOk()->json('items'))->pluck('kind')->all();
    expect($today())->toContain('reply');

    $this->travel(3)->minutes();
    $dispatched = count($dispatcher->dispatched);
    $requests = count(Http::recorded());
    coexEcho($this, 'wamid.HBgLMTY0NjcwNDM1OTUVAgARGBIyNDlBOEI5QUQ4NDc0N0FCNjMA', 'Sí, el galón está en $141.000. ¿Te lo separo?')->assertOk();
    coexEcho($this, 'wamid.HBgLMTY0NjcwNDM1OTUVAgARGBIyNDlBOEI5QUQ4NDc0N0FCNjMA', 'Sí, el galón está en $141.000. ¿Te lo separo?')->assertOk();  // Meta reintenta

    $echo = coexRun($this, fn () => Message::query()->where('direction', 'out')->get());
    expect($echo)->toHaveCount(1);
    $echo = $echo->first();
    expect($echo->source_type)->toBe('business_app')->and($echo->kind->value)->toBe('service')->and($echo->status)->toBe('sent')
        ->and($echo->sent_at)->toBeNull()->and($echo->payload['text'])->toBe('Sí, el galón está en $141.000. ¿Te lo separo?')
        ->and($echo->idempotency_key)->toBe('wa:wamid.HBgLMTY0NjcwNDM1OTUVAgARGBIyNDlBOEI5QUQ4NDc0N0FCNjMA');
    // Cifrado en reposo como cualquier mensaje.
    expect((string) DB::table('messages')->where('id', $echo->id)->value('payload'))->not->toContain('galón');
    // No pasó por el despachador (sin cupos de frecuencia, sin cobro de marketing) ni por el proveedor.
    expect($dispatcher->dispatched)->toHaveCount($dispatched)->and(Http::recorded())->toHaveCount($requests);

    $c = coexRun($this, fn () => Conversation::query()->firstOrFail());
    expect($c->awaiting_since)->toBeNull()->and($c->unread_count)->toBe(0)->and($c->first_response_seconds)->toBe(180)
        ->and($c->ai_mode)->toBe('off')->and($c->handoff_reason)->toBe('business_app')
        // La ventana de 24 h de la Cloud API no cambia por un mensaje de la app.
        ->and($c->window_expires_at?->getTimestamp())->toBe(now()->subMinutes(3)->addDay()->getTimestamp());
    $events = coexRun($this, fn () => ConversationEvent::query()->where('conversation_id', $c->id)->get());
    expect($events->where('kind', 'message')->first()?->actor)->toBe('business_app')
        ->and($events->where('kind', 'first_response')->first()?->data)->toBe(['seconds' => 180])
        ->and($events->where('kind', 'handoff')->first()?->actor)->toBe('business_app');
    expect(coexRun($this, fn () => Lead::query()->find($leadId))->first_response_at)->not->toBeNull()
        ->and($today())->not->toContain('reply');

    $detail = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/inbox/conversations/{$c->publicId()}")->assertOk();
    $last = collect($detail->json('messages'))->last();
    expect($last['author'])->toBe(['kind' => 'phone', 'name' => 'Desde el celular'])->and($last['source'])->toBe('business_app')
        ->and($last['text'])->toContain('141.000');

    // No cuenta en el uso del plan (Meta no cobra lo que sale de la app).
    expect(app(UsageCalculator::class)->compute($this->ws, now()->format('Y-m'))['wa_service'])->toBe(0);
});

it('un estado de Meta de un mensaje del celular no emite eventos; un número nuevo crea el contacto sin consentimiento', function () {
    coexEcho($this, 'wamid.NUEVO1', 'Hola Pablo, ya tenemos tu pedido', '573209998877')->assertOk();
    $contact = coexRun($this, fn () => Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573209998877'))->firstOrFail());
    expect($contact->getAttribute('first_source'))->toMatchArray(['channel' => 'whatsapp', 'via' => BusinessAppContacts::ORIGIN])
        ->and($contact->getAttribute('lifecycle_stage'))->toBe('lead')
        ->and(DB::table('contact_consents')->where('contact_id', $contact->id)->count())->toBe(0)
        ->and(DB::table('contact_identities')->where('contact_id', $contact->id)->where('type', 'wa_bsuid')->value('value'))->toBe('CO.1A2B3C');
    $conversation = coexRun($this, fn () => Conversation::query()->where('contact_id', $contact->id)->firstOrFail());
    expect($conversation->status)->toBe('open')->and($conversation->window_expires_at)->toBeNull();

    coexWebhook($this, 'messages', ['statuses' => [['id' => 'wamid.NUEVO1', 'status' => 'read', 'timestamp' => (string) now()->getTimestamp(), 'recipient_id' => '573209998877']]])->assertOk();
    $echo = coexRun($this, fn () => Message::query()->where('provider_message_id', 'wamid.NUEVO1')->firstOrFail());
    expect($echo->read_at)->toBeNull()->and($echo->status)->toBe('sent')
        ->and($this->chRows($this->ws, "SELECT count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32} AND event LIKE 'message.%'")[0]['n'])->toBe(0);
});

it('edita y borra lo escrito desde el celular; sin teléfono ni BSUID conocido no hay a quién ligarlo', function () {
    coexEcho($this, 'wamid.E1', 'El envío sale mañana')->assertOk();
    coexWebhook($this, 'smb_message_echoes', ['message_echoes' => [['from' => '573000000000', 'to' => '573001234567', 'to_user_id' => 'CO.1A2B3C', 'id' => 'wamid.E1-edit',
        'timestamp' => (string) now()->getTimestamp(), 'type' => 'edit', 'edit' => ['original_message_id' => 'wamid.E1', 'message' => ['type' => 'text', 'text' => ['body' => 'El envío sale el lunes']]]]]])->assertOk();
    $m = coexRun($this, fn () => Message::query()->where('provider_message_id', 'wamid.E1')->firstOrFail());
    expect($m->payload['text'])->toBe('El envío sale el lunes')->and($m->payload['edited'])->toBeTrue();

    coexWebhook($this, 'smb_message_echoes', ['message_echoes' => [['from' => '573000000000', 'to' => '573001234567', 'to_user_id' => 'CO.1A2B3C', 'id' => 'wamid.E1-revoke',
        'timestamp' => (string) now()->getTimestamp(), 'type' => 'revoke', 'revoke' => ['original_message_id' => 'wamid.E1']]]])->assertOk();
    $m->refresh();
    expect($m->payload)->not->toHaveKey('text')->and($m->payload['revoked'])->toBeTrue()
        ->and(coexRun($this, fn () => Message::query()->count()))->toBe(1);
    $c = coexRun($this, fn () => Conversation::query()->firstOrFail());
    $detail = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/inbox/conversations/{$c->publicId()}")->assertOk();
    expect($detail->json('messages.0.type'))->toBe('revoked')->and($detail->json('messages.0.text'))->toBeNull();

    // Solo el BSUID de alguien que la plataforma no conoce: se ignora sin crear nada.
    coexEcho($this, 'wamid.SOLO-BSUID', 'Hola', null, 'CO.DESCONOCIDO')->assertOk();
    expect(coexRun($this, fn () => Message::query()->where('provider_message_id', 'wamid.SOLO-BSUID')->exists()))->toBeFalse();
});

it('importa el historial por partes, idempotente y cifrado, sin disparar nada ni crear consentimiento', function () {
    Event::fake([InboundMessageReceived::class]);
    Bus::fake([RunConversationAgent::class, ExtractLeadBrief::class]);
    $t = now()->subDays(40)->getTimestamp();
    $chunk = fn (int $progress) => ['history' => [[
        'metadata' => ['phase' => 1, 'chunk_order' => 2, 'progress' => $progress],
        'threads' => [
            ['id' => '573001234567', 'context' => ['wa_id' => '573001234567', 'user_id' => 'CO.ANA'], 'messages' => [
                ['from' => '573000000000', 'id' => 'wamid.H1', 'timestamp' => (string) $t, 'type' => 'text', 'text' => ['body' => 'Aquí está la cotización que pediste'], 'history_context' => ['status' => 'read']],
                ['from' => '573000000000', 'id' => 'wamid.H2', 'timestamp' => (string) ($t + 15), 'type' => 'media_placeholder', 'history_context' => ['status' => 'played']],
                ['from' => '573001234567', 'from_user_id' => 'CO.ANA', 'id' => 'wamid.H3', 'timestamp' => (string) ($t + 60), 'type' => 'text', 'text' => ['body' => 'No me escriban'], 'history_context' => ['status' => 'read']],
            ]],
            ['id' => '573109998877', 'context' => ['wa_id' => '573109998877', 'user_id' => 'CO.LUIS'], 'messages' => [
                ['from' => '573109998877', 'from_user_id' => 'CO.LUIS', 'id' => 'wamid.H4', 'timestamp' => (string) ($t + 100), 'type' => 'text', 'text' => ['body' => 'Hola, ¿hacen envíos a Pereira?'], 'history_context' => ['status' => 'read']],
                ['from' => '573109998877', 'from_user_id' => 'CO.LUIS', 'id' => 'wamid.H5', 'timestamp' => (string) ($t + 200), 'type' => 'text', 'text' => ['body' => 'Baja por favor'], 'history_context' => ['status' => 'read']],
            ]],
        ],
    ]]];

    coexWebhook($this, 'history', $chunk(55))->assertOk();
    coexWebhook($this, 'history', $chunk(55))->assertOk();  // la misma parte otra vez: no duplica

    $messages = coexRun($this, fn () => Message::query()->orderBy('created_at')->get());
    expect($messages)->toHaveCount(5)
        ->and($messages->pluck('source_type')->unique()->all())->toBe(['history'])
        ->and($messages->pluck('direction')->all())->toBe(['out', 'out', 'in', 'in', 'in'])
        ->and($messages->first()->created_at?->getTimestamp())->toBe($t)
        ->and($messages[1]->payload['type'])->toBe('media_placeholder')
        ->and((string) DB::table('messages')->where('provider_message_id', 'wamid.H4')->value('payload'))->not->toContain('Pereira');

    $luis = coexRun($this, fn () => Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573109998877'))->firstOrFail());
    $conversations = coexRun($this, fn () => Conversation::query()->get());
    expect($conversations)->toHaveCount(2)
        ->and($conversations->pluck('status')->unique()->all())->toBe(['closed'])
        ->and($conversations->pluck('window_expires_at')->filter()->all())->toBe([])
        ->and(DB::table('contact_consents')->where('contact_id', $luis->id)->where('status', 'granted')->count())->toBe(0);
    // Una baja escrita en la app se respeta con su fecha, sin responder nada; la de Ana es anterior a su
    // consentimiento del checkout (de ayer), así que ese consentimiento sigue vigente.
    expect(DB::table('contact_consents')->where('contact_id', $luis->id)->where('channel', 'whatsapp')->value('status'))->toBe('revoked')
        ->and(DB::table('contact_consents')->where('contact_id', $this->contact->id)->where('channel', 'whatsapp')->value('status'))->toBe('granted')
        ->and(coexRun($this, fn () => Message::query()->where('direction', 'out')->where('source_type', '!=', 'history')->count()))->toBe(0);
    Event::assertNotDispatched(InboundMessageReceived::class);
    Bus::assertNotDispatched(RunConversationAgent::class);
    Bus::assertNotDispatched(ExtractLeadBrief::class);
    expect($this->push->sent)->toBe([])
        ->and(coexRun($this, fn () => Lead::query()->count()))->toBe(0)
        ->and($this->chRows($this->ws, "SELECT count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32} AND (event LIKE 'message.%' OR event = 'whatsapp.inbound')")[0]['n'])->toBe(0);

    // El adjunto de las dos últimas semanas llega después del marcador.
    coexWebhook($this, 'history', ['messages' => [['from' => '573000000000', 'id' => 'wamid.H2', 'timestamp' => (string) ($t + 15), 'type' => 'image',
        'image' => ['caption' => 'Muestra del color', 'mime_type' => 'image/jpeg', 'sha256' => '3f9d94d3', 'id' => '24230790383178626']]]])->assertOk();
    $media = coexRun($this, fn () => Message::query()->where('provider_message_id', 'wamid.H2')->firstOrFail());
    expect($media->payload['type'])->toBe('image')->and($media->payload['media'])->toBe(['id' => '24230790383178626', 'mime' => 'image/jpeg'])
        ->and(coexRun($this, fn () => MessageAttachment::query()->where('message_id', $media->id)->value('provider_media_id')))->toBe('24230790383178626');

    coexWebhook($this, 'history', ['history' => [['metadata' => ['phase' => 2, 'chunk_order' => 1, 'progress' => 100], 'threads' => []]]])->assertOk();
    $state = coexRun($this, fn () => ChannelAccount::query()->find($this->wa->id))->config['history'];
    expect($state)->toMatchArray(['phase' => 2, 'progress' => 100, 'messages' => 5, 'declined' => false]);
});

it('registra si el negocio no compartió el historial', function () {
    coexWebhook($this, 'history', ['history' => [['errors' => [['code' => 2593109, 'title' => 'History sync is turned off by the business from the WhatsApp Business App',
        'message' => 'History sync is turned off by the business from the WhatsApp Business App', 'error_data' => ['details' => 'History sharing is turned off by the business']]]]]])->assertOk();
    expect(coexRun($this, fn () => ChannelAccount::query()->find($this->wa->id))->config['history'])->toMatchArray(['declined' => true, 'messages' => 0]);
});

it('el historial y la libreta viajan cifrados en la cola', function () {
    expect(is_subclass_of(ImportBusinessAppHistoryJob::class, ShouldBeEncrypted::class))->toBeTrue();
});

it('la libreta de la app crea o completa contactos sin consentimiento y una baja no borra a nadie', function () {
    coexRun($this, fn () => $this->contact->forceFill(['first_name' => 'Ana María'])->save());
    $sync = fn (array $items) => coexWebhook($this, 'smb_app_state_sync', ['state_sync' => $items]);
    $sync([
        ['type' => 'contact', 'contact' => ['full_name' => 'Pablo Morales', 'first_name' => 'Pablo', 'phone_number' => '573157776655', 'user_id' => 'CO.PABLO'], 'action' => 'add', 'metadata' => ['timestamp' => (string) now()->getTimestamp()]],
        ['type' => 'contact', 'contact' => ['full_name' => 'Ana Cliente Fiel', 'first_name' => 'Ana', 'phone_number' => '573001234567', 'user_id' => 'CO.ANA'], 'action' => 'add', 'metadata' => ['timestamp' => (string) now()->getTimestamp()]],
        ['type' => 'contact', 'contact' => ['user_id' => 'CO.SINTEL'], 'action' => 'add', 'metadata' => ['timestamp' => (string) now()->getTimestamp()]],
    ])->assertOk();

    $pablo = coexRun($this, fn () => Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573157776655'))->firstOrFail());
    expect($pablo->first_name)->toBe('Pablo')->and($pablo->last_name)->toBe('Morales')
        ->and($pablo->getAttribute('first_source'))->toMatchArray(['via' => BusinessAppContacts::ORIGIN])
        ->and(DB::table('contact_consents')->where('contact_id', $pablo->id)->count())->toBe(0);
    // El nombre que ya tenía (del pedido o escrito por una persona) gana sobre la libreta.
    expect($this->contact->fresh()?->first_name)->toBe('Ana María');
    expect(coexRun($this, fn () => ChannelAccount::query()->find($this->wa->id))->config['contacts'])->toMatchArray(['created' => 1, 'updated' => 1]);

    // Lo que escribe después el dueño a alguien de la libreta sin teléfono visible se liga por el BSUID.
    coexEcho($this, 'wamid.POR-BSUID', '¿Te llegó la pintura?', null, 'CO.PABLO')->assertOk();
    expect(coexRun($this, fn () => Message::query()->where('provider_message_id', 'wamid.POR-BSUID')->value('contact_id')))->toBe($pablo->id);

    $before = coexRun($this, fn () => Contact::query()->count());
    $sync([['type' => 'contact', 'contact' => ['phone_number' => '573157776655', 'user_id' => 'CO.PABLO'], 'action' => 'remove', 'metadata' => ['timestamp' => (string) now()->getTimestamp()]]])->assertOk();
    expect(coexRun($this, fn () => Contact::query()->count()))->toBe($before)->and($pablo->fresh()?->deleted_at)->toBeNull();
});

it('Meta desconecta el número (account_update): queda desconectado, avisa una sola vez y sale del aviso al reconectar', function () {
    Mail::fake();
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $update = fn () => coexWebhook($this, 'account_update', ['event' => 'PARTNER_REMOVED', 'waba_info' => ['waba_id' => '2222', 'owner_business_id' => '2329417887457253'],
        'disconnection_info' => ['reason' => 'PRIMARY_INACTIVITY', 'initiated_by' => 'SYSTEM']], '2949482758682047');
    $update()->assertOk();
    $update()->assertOk();

    $wa = coexRun($this, fn () => ChannelAccount::query()->find($this->wa->id));
    expect($wa->status)->toBe('disconnected')->and($wa->is_default)->toBeFalse()->and($wa->alert_state)->toBe('disconnected')
        ->and($wa->last_error)->toContain('inactiva');
    Mail::assertSent(ConnectionAlertMail::class, 2);  // dueña y administración, una vez
    Mail::assertSent(ConnectionAlertMail::class, fn ($m) => $m->hasTo($admin->email) && $m->state === 'disconnected');
    Mail::assertNotSent(ConnectionAlertMail::class, fn ($m) => $m->hasTo($marketing->email));
    expect(DB::table('audit_logs')->where('action', 'channel.disconnected_by_provider')->count())->toBe(1);

    $alerts = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/connections/alerts")->assertOk()->json('data');
    expect($alerts)->toHaveCount(1)->and($alerts[0])->toMatchArray(['kind' => 'channel', 'provider' => 'meta_whatsapp', 'state' => 'disconnected', 'name' => '+57 300 0000000']);

    // Lo que llegue mientras tanto no entra por la cuenta desconectada.
    coexEcho($this, 'wamid.DESPUES', 'Hola')->assertOk();
    expect(coexRun($this, fn () => Message::query()->count()))->toBe(0);

    // Reconectado el mismo número, el aviso sobra.
    coexRun($this, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WhatsApp',
        'status' => 'active', 'external_id' => '1111', 'config' => ['waba_id' => '2222', 'phone_number_id' => '1111', 'access_token' => 'EAAB-nuevo']]));
    expect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/connections/alerts")->json('data'))->toBe([]);
});

it('cada espacio de trabajo solo ve lo de su número: el mismo cliente en otro espacio no se toca', function () {
    $other = $this->userWith2fa();
    $wsB = $this->workspaceFor($other, 'Ferretería B');
    $contactB = app(CurrentWorkspace::class)->run($wsB, fn () => Contact::query()->create([
        'workspace_id' => $wsB->id, 'phone' => '+573001234567', 'phone_bidx' => BlindIndex::phone($wsB->id, '+573001234567'),
    ]));

    coexEcho($this, 'wamid.TENANT', 'Hola Ana')->assertOk();
    coexWebhook($this, 'smb_app_state_sync', ['state_sync' => [['type' => 'contact', 'contact' => ['full_name' => 'Ana', 'first_name' => 'Ana', 'phone_number' => '573001234567', 'user_id' => 'CO.ANA'], 'action' => 'add']]])->assertOk();

    expect(Message::withoutWorkspaceScope()->where('workspace_id', $wsB->id)->count())->toBe(0)
        ->and(ContactIdentity::withoutWorkspaceScope()->where('contact_id', $contactB->id)->where('type', 'wa_bsuid')->exists())->toBeFalse()
        ->and(Message::withoutWorkspaceScope()->where('provider_message_id', 'wamid.TENANT')->value('contact_id'))->toBe($this->contact->id);
    $this->actingAs($other)->getJson("/api/w/{$wsB->publicId()}/connections/alerts")->assertOk()->assertJsonCount(0, 'data');
});
