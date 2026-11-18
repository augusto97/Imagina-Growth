<?php

use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Jobs\SendMessageJob;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\TrackedLink;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\SesClientFactory;
use App\Support\Tenancy\CurrentWorkspace;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    // Martes 17 de noviembre de 2026, 10:00 en Bogotá: dentro de la ventana legal.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
});

/** Respuesta de Meta para esta prueba (ClickHouse también usa el cliente HTTP y pasa de largo). */
function fakeMeta(mixed $response = null): void
{
    Http::fake(['graph.test/*' => $response ?? Http::response(['messages' => [['id' => 'wamid.HBg1']]])]);
}

/** @return list<HttpRequest> solo las peticiones a un proveedor */
function providerRequests(string $host = 'graph.test'): array
{
    return array_values(array_map(fn (array $pair) => $pair[0], Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $host))->all()));
}

function send($test, ?string $templateId = 'tpl_wa', Channel $channel = Channel::WhatsApp, MessageKind $kind = MessageKind::Marketing, array $extra = []): DispatchResult
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, array_key_exists('contact', $extra) ? $extra['contact'] : $test->contact->id, $channel, $kind, $extra['source'] ?? 'flow',
        sourceId: 'fl_01', templateId: $templateId, idempotencyKey: $extra['key'] ?? null, channelAccountId: $extra['account'] ?? null,
        flowId: $extra['flow'] ?? null, to: $extra['to'] ?? null, isTest: $extra['test'] ?? false, context: $extra['context'] ?? [],
    )));
}

function msgs($test): \Illuminate\Support\Collection
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Message::query()->get());
}

it('envía un WhatsApp de marketing por la Marketing Messages API con los enlaces rastreados y emite los eventos', function () {
    fakeMeta();
    $result = send($this, extra: ['key' => 'run:R1:n1:1']);
    expect($result->status)->toBe(DispatchResult::QUEUED);

    $message = msgs($this)->first();
    expect($message->status)->toBe('sent')->and($message->provider_message_id)->toBe('wamid.HBg1')->and($message->provider)->toBe('meta_cloud');
    $requests = providerRequests();
    expect($requests)->toHaveCount(1)->and((function (HttpRequest $r) {
        $body = $r->data();
        $params = $body['template']['components'][0]['parameters'];

        return str_ends_with($r->url(), '/1111/marketing_messages') && $body['to'] === '573001234567'
            && $body['template']['name'] === 'carrito_abandonado'
            && preg_match('#^https://go\.test/c/[A-Za-z0-9]{8}$#', $params[1]['text']) === 1
            && preg_match('/^[A-Za-z0-9]{8}$/', $body['template']['components'][1]['parameters'][0]['text']) === 1
            && $r->hasHeader('Authorization', 'Bearer EAAB-token-de-prueba');
    })($requests[0]))->toBeTrue();
    $links = app(CurrentWorkspace::class)->run($this->ws, fn () => TrackedLink::query()->pluck('url')->all());
    expect($links)->toEqualCanonicalizing(['https://tienda.co/carrito', 'https://tienda.co/checkout']);
    $events = array_column($this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event LIKE 'message.%' ORDER BY event"), 'event');
    expect($events)->toBe(['message.queued', 'message.sent']);
});

it('la misma clave de idempotencia nunca envía dos veces', function () {
    fakeMeta();
    $first = send($this, extra: ['key' => 'run:R1:n1:1']);
    $second = send($this, extra: ['key' => 'run:R1:n1:1']);
    expect($second->status)->toBe(DispatchResult::DUPLICATE)->and($second->messageId)->toBe($first->messageId);
    expect(providerRequests())->toHaveCount(1)->and(msgs($this))->toHaveCount(1);
});

it('un domingo el marketing se aplaza sin crear mensaje; lo transaccional sale', function () {
    fakeMeta();
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'America/Bogota'));
    $result = send($this, extra: ['key' => 'run:R2:n1:1']);
    expect($result->status)->toBe(DispatchResult::DEFERRED)->and($result->reason)->toBe('sunday')
        ->and($result->until?->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-23 07:00');
    expect(msgs($this))->toHaveCount(0)->and(providerRequests())->toBe([]);
    // Con la misma clave, el lunes a las 7:00, sale.
    $this->travelTo(CarbonImmutable::parse('2026-11-23 07:00:00', 'America/Bogota'));
    expect(send($this, extra: ['key' => 'run:R2:n1:1'])->status)->toBe(DispatchResult::QUEUED);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 22:00:00', 'America/Bogota'));
    expect(send($this, 'tpl_wa', kind: MessageKind::Utility)->status)->toBe(DispatchResult::QUEUED);
});

it('sin consentimiento o sin cuenta del canal se niega sin crear mensaje', function () {
    DB::table('contact_consents')->where('contact_id', $this->contact->id)->where('channel', 'whatsapp')->update(['status' => 'revoked']);
    expect(send($this)->reason)->toBe('no_consent');
    $this->email->forceFill(['status' => 'paused'])->save();
    expect(send($this, 'tpl_email', Channel::Email)->reason)->toBe('channel_unavailable');
    expect(send($this, 'tpl_wa', Channel::Sms)->reason)->toBe('channel_unavailable');
    expect(msgs($this))->toHaveCount(0);
});

it('frecuencia: el segundo comercial del día se aplaza; si la plantilla falla, el cupo vuelve', function () {
    fakeMeta();
    expect(send($this, 'roto')->reason)->toBe('template_error');
    expect(send($this)->status)->toBe(DispatchResult::QUEUED);
    $second = send($this, 'tpl_email', Channel::Email);
    expect($second->status)->toBe(DispatchResult::DEFERRED)->and($second->reason)->toBe('frequency_day');
});

it('el job revisa otra vez la ventana: si la cola se demoró hasta las 19:00 el mensaje queda aplazado y se reencola al abrir', function () {
    Queue::fake();
    fakeMeta();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 18:59:50', 'America/Bogota'));
    $result = send($this);
    Queue::assertPushedOn('messages:whatsapp', SendMessageJob::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 19:00:20', 'America/Bogota'));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app()->call([new SendMessageJob($this->ws->id, (string) $result->messageId), 'handle']));
    $message = msgs($this)->first();
    expect($message->status)->toBe('deferred')->and($message->policy_reason)->toBe('outside_hours')
        ->and(CarbonImmutable::instance($message->deferred_until)->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-18 07:00')
        ->and(providerRequests())->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 07:00:30', 'America/Bogota'));
    $this->artisan('igr:messages:requeue')->assertSuccessful();
    expect(msgs($this)->first()->status)->toBe('queued');
    Queue::assertPushed(SendMessageJob::class, 2);
});

it('un error definitivo del proveedor deja el mensaje fallido y devuelve el cupo; uno transitorio lo deja en cola', function () {
    fakeMeta(Http::sequence()
        ->push(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)
        ->push(['error' => ['code' => 1, 'message' => 'Temporal']], 500));
    send($this);
    $failed = msgs($this)->first();
    expect($failed->status)->toBe('failed')->and($failed->error_code)->toBe('131026');
    $events = array_column($this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'message.failed'"), 'event');
    expect($events)->toHaveCount(1);
    // El cupo del día volvió: el siguiente comercial sale (y queda en cola por el 500).
    expect(send($this, 'tpl_wa')->status)->toBe(DispatchResult::QUEUED);
    $retry = msgs($this)->sortByDesc('id')->first();
    expect($retry->status)->toBe('queued')->and($retry->payload['attempts'])->toBe(1);
});

it('el correo de marketing lleva List-Unsubscribe de un clic, el pie legal y los enlaces rastreados', function () {
    $sent = [];
    $mock = new MockHandler;
    $mock->append(function (CommandInterface $cmd) use (&$sent) {
        $sent[] = $cmd->toArray();

        return new Result(['MessageId' => 'ses-0001']);
    });
    app()->instance(SesClientFactory::class, new class($mock) extends SesClientFactory
    {
        public function __construct(private MockHandler $mock) {}

        public function make(): SesV2Client
        {
            return new SesV2Client(['version' => '2019-09-27', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $this->mock]);
        }
    });
    expect(send($this, 'tpl_email', Channel::Email)->status)->toBe(DispatchResult::QUEUED);
    $raw = (string) $sent[0]['Content']['Raw']['Data'];
    $decoded = quoted_printable_decode($raw);
    expect($sent[0]['Destination']['ToAddresses'])->toBe(['ana@ejemplo.com'])
        ->and($raw)->toMatch('#List-Unsubscribe: <https://go\.test/u/[A-Za-z0-9_.-]+>#')
        ->and($raw)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click')
        ->and($decoded)->toMatch('#href="https://go\.test/c/[A-Za-z0-9]{8}"#')
        ->and($decoded)->toContain('mailto:hola@tienda.co')
        ->and($decoded)->toContain('Recibes este correo porque aceptaste recibir novedades de Pinturas La 14');
    expect(msgs($this)->first()->provider_message_id)->toBe('ses-0001');
});

it('el modo prueba manda al destino explícito aunque sea domingo; Zender compone el texto de la plantilla', function () {
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.T']]]), 'zender.test/*' => Http::response(['status' => 200, 'data' => ['messageId' => 77]])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 21:00:00', 'America/Bogota'));
    expect(send($this, extra: ['contact' => null, 'to' => '+573009998877', 'test' => true])->status)->toBe(DispatchResult::QUEUED);
    $meta = providerRequests();
    expect($meta)->toHaveCount(1)->and(str_ends_with($meta[0]->url(), '/1111/messages'))->toBeTrue()->and($meta[0]->data()['to'])->toBe('573009998877');

    $zender = app(CurrentWorkspace::class)->run($this->ws, function () {
        WaTemplate::query()->create(['workspace_id' => $this->ws->id, 'channel_account_id' => $this->wa->id, 'name' => 'carrito_abandonado', 'language' => 'es', 'category' => 'UTILITY', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu carrito: {{2}}']]]);

        return ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'zender', 'display_name' => 'Zender', 'status' => 'active',
            'config' => ['base_url' => 'https://zender.test', 'api_secret' => 'sec', 'account_id' => 'dev1']]);
    });
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    expect(send($this, kind: MessageKind::Utility, extra: ['account' => $zender->id])->status)->toBe(DispatchResult::QUEUED);
    $zenderSent = providerRequests('zender.test');
    expect($zenderSent)->toHaveCount(1)->and($zenderSent[0]->url())->toStartWith('https://zender.test/api/send/whatsapp')
        ->and((string) $zenderSent[0]['message'])->toMatch('#^Hola Ana, tu carrito: https://go\.test/c/[A-Za-z0-9]{8}$#');
    // Marketing por Zender: nunca.
    expect(send($this, extra: ['account' => $zender->id])->reason)->toBe('unofficial_marketing');
});

it('los topes de la cuenta (Zender por hora, calentamiento del dominio) aplazan sin perder el mensaje', function () {
    config(['igr-messaging.zender.hourly_cap' => 1]);
    Http::fake(['zender.test/*' => Http::response(['status' => 200, 'data' => ['messageId' => 1]])]);
    $zender = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'zender', 'display_name' => 'Zender', 'status' => 'active',
        'config' => ['base_url' => 'https://zender.test', 'api_secret' => 'sec', 'account_id' => 'dev1']]));
    foreach ([1, 2] as $i) {
        send($this, null, kind: MessageKind::Transactional, extra: ['account' => $zender->id, 'context' => ['text' => "Pedido {$i} confirmado"]]);
    }
    expect(msgs($this)->pluck('status')->sort()->values()->all())->toBe(['deferred', 'sent'])
        ->and(msgs($this)->firstWhere('status', 'deferred')->policy_reason)->toBe('account_cap');
});

it('SMS por Twilio con callback de estado; el calentamiento del dominio aplaza al pasar el tope del día', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
    $sms = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'sms', 'provider' => 'twilio', 'display_name' => 'SMS', 'status' => 'active', 'is_default' => true,
        'config' => ['account_sid' => 'AC'.str_repeat('c', 32), 'auth_token' => 'tok', 'from' => '+15005550006']]));
    expect(send($this, null, Channel::Sms, MessageKind::Transactional, ['context' => ['text' => 'Tu pedido 1234 va en camino']])->status)->toBe(DispatchResult::QUEUED);
    $twilio = providerRequests('api.twilio.com');
    expect($twilio)->toHaveCount(1)->and($twilio[0]['To'])->toBe('+573001234567')->and($twilio[0]['Body'])->toBe('Tu pedido 1234 va en camino')
        ->and($twilio[0]['StatusCallback'])->toBe('https://go.test/webhooks/twilio/'.$sms->publicId());

    config(['igr-messaging.warmup' => [1]]);
    $this->email->forceFill(['warmup_started_at' => now()])->save();
    foreach (['a@x.co', 'b@x.co'] as $to) {
        send($this, null, Channel::Email, MessageKind::Transactional, ['contact' => null, 'to' => $to, 'context' => ['subject' => 'Aviso', 'text' => 'Hola']]);
    }
    $emails = msgs($this)->where('channel', Channel::Email)->pluck('policy_reason', 'status')->all();
    expect($emails)->toHaveKey('sent')->and($emails['deferred'] ?? null)->toBe('account_cap');
});
