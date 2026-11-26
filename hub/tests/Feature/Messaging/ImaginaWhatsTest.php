<?php

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsSignature;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

/*
 * Imagina Whats (M07, cumplimiento.md §5.2): conexión con la clave de API, webhook firmado, envío de texto con
 * idempotencia, estados, entrantes, bajas y los límites del WhatsApp no oficial. Todo con Http::fake.
 */
beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    config(['igr-messaging.imagina_whats.url' => 'https://whats.test']);
    $this->api = "/api/w/{$this->ws->publicId()}";
    $this->iw = new ArrayObject(['abilities' => ['account.view', 'wa.send', 'wa.read', 'wa.accounts.view', 'webhooks.view', 'webhooks.manage'],
        'numbers' => [['id' => 7, 'phone' => '+573105550000', 'label' => 'Ventas', 'status' => 'connected', 'is_enabled' => true],
            ['id' => 8, 'phone' => '+573105551111', 'display_name' => 'Soporte', 'status' => 'logged_out', 'is_enabled' => true]],
        'webhooks' => [], 'send' => null]);
    $iw = $this->iw;
    Http::fake(['whats.test/*' => function (HttpRequest $r) use ($iw) {
        $path = parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/v1/me' && isset($iw['me_error']) => $iw['me_error'],
            $path === '/api/v1/me' => Http::response(['data' => ['workspace' => ['name' => 'Clínica en Imagina Whats'], 'credits' => ['balance' => 120], 'token' => ['abilities' => $iw['abilities']]]]),
            $path === '/api/v1/whatsapp/accounts' => Http::response(['data' => $iw['numbers'], 'meta' => ['next_cursor' => null]]),
            $path === '/api/v1/whatsapp/accounts/7' => Http::response(['data' => $iw['numbers'][0]]),
            $path === '/api/v1/webhooks' && $r->method() === 'GET' => Http::response(['data' => $iw['webhooks']]),
            $path === '/api/v1/webhooks' && $r->method() === 'POST' => Http::response(['data' => ['id' => 31, 'secret' => 'sec-nuevo', 'url' => $r['url'], 'events' => $r['events']]], 201),
            $path === '/api/v1/webhooks/31/rotate-secret' => Http::response(['data' => ['id' => 31, 'secret' => 'sec-rotado']]),
            str_starts_with($path, '/api/v1/webhooks/31') => Http::response($r->method() === 'DELETE' ? null : ['data' => ['id' => 31]], $r->method() === 'DELETE' ? 204 : 200),
            $path === '/api/v1/messages/whatsapp' => $iw['send'] ?? Http::response(['data' => ['id' => 501, 'status' => 'queued', 'reference' => $r['reference']]], 202),
            default => Http::response(['error' => ['code' => 'not_found', 'message' => 'No existe']], 404),
        };
    }]);
});

function iwRequests(string $path = '', ?string $method = null): array
{
    return array_values(array_map(fn (array $pair) => $pair[0], Http::recorded(fn (HttpRequest $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/v1'.$path && ($method === null || $r->method() === $method))->all()));
}

/** Una cuenta de Imagina Whats ya conectada (con su webhook). */
function iwAccount($test, string $status = 'active'): ChannelAccount
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => ChannelAccount::query()->create([
        'workspace_id' => $test->ws->id, 'channel' => 'whatsapp', 'provider' => 'imagina_whats', 'display_name' => 'Ventas', 'status' => $status,
        'external_id' => '7', 'identity' => '+573105550000', 'config' => ['api_key' => 'iw-clave-de-prueba', 'wa_account_id' => '7', 'webhook_id' => '31', 'webhook_secret' => 'sec-iw'],
    ]));
}

function iwPost($test, ChannelAccount $account, array $envelope, ?string $secret = 'sec-iw', ?string $delivery = null)
{
    $body = json_encode($envelope + ['id' => $delivery ?? (string) Str::uuid(), 'sent_at' => now()->toIso8601String()]);

    return $test->call('POST', '/webhooks/imagina-whats/'.$account->publicId(), [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => $secret !== null ? ImaginaWhatsSignature::sign($body, $secret) : null,
        'HTTP_X_WEBHOOK_DELIVERY_ID' => $delivery,
    ]), $body);
}

function iwSend($test, ChannelAccount $account, MessageKind $kind = MessageKind::Utility, array $context = ['text' => 'Tu cita es mañana a las 9:00.']): DispatchResult
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $test->contact->id, Channel::WhatsApp, $kind, 'system', channelAccountId: $account->id, context: $context,
    )));
}

it('revisa la clave: los permisos que faltan y los números con su estado, sin guardar nada', function () {
    $this->iw['abilities'] = ['account.view', 'wa.send', 'wa.accounts.view'];
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/imagina-whats/check", ['api_key' => 'iw-clave-de-prueba'])->assertOk();
    expect($res->json('data.workspace'))->toBe('Clínica en Imagina Whats')
        ->and($res->json('data.missing'))->toBe(['wa.read', 'webhooks.view', 'webhooks.manage'])
        ->and($res->json('data.numbers.0'))->toMatchArray(['id' => 7, 'phone' => '+573105550000', 'name' => 'Ventas', 'status' => 'connected'])
        ->and($res->json('data.numbers.1'))->toMatchArray(['id' => 8, 'name' => 'Soporte', 'status' => 'logged_out']);
    expect(iwRequests('/me')[0]->header('Authorization')[0])->toBe('Bearer iw-clave-de-prueba');
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->where('provider', 'imagina_whats')->count()))->toBe(0);

    // Una clave revocada se explica en palabras.
    $this->iw['me_error'] = Http::response(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']], 401);
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/imagina-whats/check", ['api_key' => 'iw-clave-vieja-x'])
        ->assertUnprocessable()->assertJsonPath('errors.api_key.0', fn (string $m) => str_contains($m, 'no es válida'));
});

it('solo el dueño la conecta, aceptando el riesgo, con un número conectado; registra el webhook firmado sin devolver secretos', function () {
    $body = ['api_key' => 'iw-clave-de-prueba', 'wa_account_id' => 7];
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, true);
    $this->actingAs($admin)->postJson("{$this->api}/channels/whatsapp/imagina-whats", $body + ['accept_risk' => true])->assertForbidden();
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/imagina-whats", $body)->assertUnprocessable()->assertJsonValidationErrors('accept_risk');
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/imagina-whats", ['wa_account_id' => 8] + $body + ['accept_risk' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('wa_account_id');

    $res = $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/imagina-whats", $body + ['accept_risk' => true])->assertCreated();
    expect($res->json('data'))->toMatchArray(['provider' => 'imagina_whats', 'display_name' => 'Ventas', 'status' => 'active', 'is_default' => false, 'last_error' => null])
        ->and($res->json('data.caps'))->toBe(['hourly' => 30, 'daily' => 200])
        ->and($res->json('webhook_problem'))->toBeNull()
        ->and(json_encode($res->json()))->not->toContain('sec-nuevo')->not->toContain('iw-clave-de-prueba');
    $account = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->where('provider', 'imagina_whats')->sole());
    expect($account->external_id)->toBe('7')->and($account->identity)->toBe('+573105550000')
        ->and($account->credential('webhook_secret'))->toBe('sec-nuevo')->and($account->credential('webhook_id'))->toBe('31')
        ->and($account->risk_accepted_at)->not->toBeNull();
    $created = iwRequests('/webhooks', 'POST')[0];
    expect($created['url'])->toBe('https://go.test/webhooks/imagina-whats/'.$account->publicId())
        ->and($created['events'])->toContain('wa.message.received', 'wa.message.delivered', 'contact.unsubscribed')
        ->and($created->header('Idempotency-Key')[0])->toStartWith('igr-webhook-')
        ->and(iwRequests('/webhooks/31/test', 'POST'))->toHaveCount(1)
        ->and(DB::table('audit_logs')->where('action', 'channel.imagina_whats_risk_accepted')->exists())->toBeTrue();

    // Conectar otra vez el mismo número: la misma cuenta y el mismo webhook, con un secreto nuevo.
    $this->iw['webhooks'] = [['id' => 31, 'url' => 'https://go.test/webhooks/imagina-whats/'.$account->publicId(), 'is_active' => true]];
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/whatsapp/imagina-whats", $body + ['accept_risk' => true])->assertCreated();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->where('provider', 'imagina_whats')->count()))->toBe(1)
        ->and($account->refresh()->credential('webhook_secret'))->toBe('sec-rotado')
        ->and(iwRequests('/webhooks', 'POST'))->toHaveCount(1);
});

it('envía texto con el número elegido, referencia e Idempotency-Key estables; la plantilla va compuesta y el marketing nunca', function () {
    $account = iwAccount($this);
    expect(iwSend($this, $account)->status)->toBe(DispatchResult::QUEUED);
    $sent = iwRequests('/messages/whatsapp');
    $message = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->where('provider', 'imagina_whats')->sole());
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->data())->toBe(['type' => 'text', 'to' => '+573001234567', 'body' => 'Tu cita es mañana a las 9:00.', 'wa_account_id' => 7, 'reference' => $message->publicId()])
        ->and($sent[0]->header('Idempotency-Key')[0])->toBe('igr-'.$message->publicId())
        ->and($message->status)->toBe('sent')->and($message->provider_message_id)->toBe('501')->and((float) $message->cost_estimated)->toBe(0.0);

    // Marketing por la conexión no oficial: nunca.
    expect(iwSend($this, $account, MessageKind::Marketing)->reason)->toBe('unofficial_marketing');
});

it('«no tiene WhatsApp» falla sin reintentar; el tope diario de calentamiento aplaza hasta mañana', function () {
    $account = iwAccount($this);
    $this->iw['send'] = Http::response(['error' => ['code' => 'not_on_whatsapp', 'message' => 'Not on WhatsApp']], 422);
    iwSend($this, $account);
    $failed = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->where('provider', 'imagina_whats')->sole());
    expect($failed->status)->toBe('failed')->and($failed->error_code)->toBe('not_on_whatsapp')->and($failed->failed_reason)->toBe('Ese número no tiene WhatsApp.')
        ->and(iwRequests('/messages/whatsapp'))->toHaveCount(1);

    $failed->delete();
    Redis::connection()->del('ws:'.$this->ws->id.':send:'.$account->id); // una por segundo por cuenta: la prueba no espera
    $this->iw['send'] = Http::response(['error' => ['code' => 'rate_limited', 'message' => 'Warm-up', 'details' => ['reason' => 'wa_warmup_cap_reached', 'reset_at' => '2026-11-18T00:00:00-05:00']]], 429, ['Retry-After' => '50400']);
    iwSend($this, $account, context: ['text' => 'Recordatorio']);
    $deferred = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->where('provider', 'imagina_whats')->sole());
    expect($deferred->status)->toBe('deferred')->and($deferred->policy_reason)->toBe('provider_cap')
        ->and($deferred->deferred_until->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-18T05:00:00Z')->toIso8601String());
});

it('el webhook exige la firma, aplica entrantes y estados una sola vez y responde 410 si la cuenta ya no está', function () {
    $account = iwAccount($this);
    $inbound = ['event' => 'wa.message.received', 'payload' => ['id' => 9001, 'channel' => 'wa', 'direction' => 'in', 'from' => '+573001234567', 'to' => '+573105550000',
        'body' => 'Hola, ¿tienen cita el jueves?', 'type' => 'text', 'status' => 'received', 'attachments' => [], 'created_at' => '2026-11-17T09:58:00-05:00']];
    iwPost($this, $account, $inbound, 'otro-secreto')->assertStatus(401);
    iwPost($this, $account, $inbound, null)->assertStatus(401);
    iwPost($this, $account, $inbound, delivery: 'd-1')->assertOk();
    iwPost($this, $account, $inbound, delivery: 'd-1')->assertOk(); // el reintento de la misma entrega no repite nada
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $in = Message::query()->where('provider', 'imagina_whats')->where('direction', 'in')->get();
        expect($in)->toHaveCount(1)->and($in[0]->contact_id)->toBe($this->contact->id)->and($in[0]->provider_message_id)->toBe('9001')
            ->and(Conversation::query()->where('contact_id', $this->contact->id)->whereNotNull('window_expires_at')->exists())->toBeTrue();
    });

    // Un grupo no es una persona: no entra.
    iwPost($this, $account, ['event' => 'wa.message.received', 'payload' => ['id' => 9002, 'direction' => 'in', 'from' => '120363-555@g.us', 'body' => 'Hola grupo']])->assertOk();

    // Estados del saliente: solo avanzan.
    iwSend($this, $account);
    iwPost($this, $account, ['event' => 'wa.message.read', 'payload' => ['id' => 501, 'direction' => 'out', 'status' => 'read', 'read_at' => '2026-11-17T10:05:00-05:00']])->assertOk();
    iwPost($this, $account, ['event' => 'wa.message.delivered', 'payload' => ['id' => 501, 'direction' => 'out', 'status' => 'delivered', 'delivered_at' => '2026-11-17T10:01:00-05:00']])->assertOk();
    $out = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->where('provider', 'imagina_whats')->where('direction', 'out')->sole());
    expect($out->status)->toBe('read')->and($out->read_at)->not->toBeNull()->and($out->delivered_at)->not->toBeNull()
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->where('provider', 'imagina_whats')->where('direction', 'in')->count()))->toBe(1);

    iwPost($this, $account, ['event' => 'webhook.test', 'payload' => ['webhook_id' => 31, 'message' => 'Prueba']])->assertOk();
    $account->forceFill(['status' => 'disconnected'])->save();
    iwPost($this, $account, $inbound)->assertStatus(410);
    $this->call('POST', '/webhooks/imagina-whats/cha_01NOEXISTE000000000000000', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(410);
});

it('una baja en Imagina Whats revoca el marketing de WhatsApp del contacto con ese número', function () {
    $account = iwAccount($this);
    iwPost($this, $account, ['event' => 'contact.unsubscribed', 'payload' => ['id' => 44, 'phone' => '+573001234567']])->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'whatsapp')->value('status'))->toBe('revoked')
            ->and(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'email')->value('status'))->toBe('granted');
    });
});

it('«Comprobar» lee el estado del número y desconectar borra el webhook en Imagina Whats', function () {
    $account = iwAccount($this);
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/{$account->publicId()}/sync")->assertOk()->assertJsonPath('data.last_error', null);
    $this->iw['numbers'] = [['id' => 7, 'phone' => '+573105550000', 'status' => 'banned', 'is_enabled' => true]];
    $this->actingAs($this->owner)->postJson("{$this->api}/channels/{$account->publicId()}/sync")->assertOk()
        ->assertJsonPath('data.last_error', 'WhatsApp bloqueó este número en Imagina Whats.');

    $this->actingAs($this->owner)->deleteJson("{$this->api}/channels/{$account->publicId()}")->assertOk();
    expect(iwRequests('/webhooks/31', 'DELETE'))->toHaveCount(1)
        ->and($account->refresh()->status)->toBe('disconnected')->and($account->credential('api_key'))->toBeNull();
});
