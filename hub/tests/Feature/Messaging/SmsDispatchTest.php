<?php

use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\TrackedLink;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Models\Template;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    // Las plantillas de SMS de estas pruebas se arman con el renderizador real de Liquid (el de los fixtures es de mentira).
    app()->forgetInstance(TemplateRenderer::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
    $this->sms = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create([
        'workspace_id' => $this->ws->id, 'channel' => 'sms', 'provider' => 'twilio', 'display_name' => 'SMS', 'status' => 'active', 'is_default' => true,
        'config' => ['account_sid' => 'AC'.str_repeat('c', 32), 'auth_token' => 'tok', 'from' => '+15005550006'],
    ]));
});

function sendSms($test, ?string $templateId, array $context = []): DispatchResult
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $test->contact->id, Channel::Sms, MessageKind::Transactional, 'flow', sourceId: 'fl_01', templateId: $templateId, context: $context,
    )));
}

/** @return list<string> el cuerpo de cada SMS que llegó a Twilio */
function twilioBodies(): array
{
    return array_values(array_map(fn (array $pair) => (string) $pair[0]['Body'], Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.twilio.com'))->all()));
}

function smsTemplate($test, string $text, bool $allow = false): Template
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Template::query()->create([
        'workspace_id' => $test->ws->id, 'channel' => 'sms', 'name' => 'SMS', 'category' => 'transactional', 'body_text' => $text, 'status' => 'ready', 'sms_allow_multipart' => $allow,
    ]));
}

it('no envía un SMS que no cabe ni sin tildes: ni cortado ni partido, sin mensaje ni enlaces', function () {
    $result = sendSms($this, null, ['text' => str_repeat('Hola ', 30).'https://tienda.co/oferta '.str_repeat('x', 30)]);
    expect($result->status)->toBe(DispatchResult::DENIED)->and($result->reason)->toBe('sms_too_long')
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->count()))->toBe(0)
        // La transacción se deshizo: el enlace rastreado que alcanzó a crearse no queda.
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => TrackedLink::query()->count()))->toBe(0)
        ->and(twilioBodies())->toBe([]);
});

it('si con tildes no cabe pero sin ellas sí, sale sin tildes en un mensaje', function () {
    $text = 'Tu cotización está lista, revísala y escríbenos. '.str_repeat('a', 60);
    expect(sendSms($this, null, ['text' => $text])->status)->toBe(DispatchResult::QUEUED);
    $message = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->sole());
    expect(twilioBodies())->toBe(['Tu cotizacion esta lista, revisala y escribenos. '.str_repeat('a', 60)])
        ->and($message->payload['sms_transliterated'] ?? null)->toBeTrue()
        ->and($message->payload['sms_parts'])->toBe(1);
});

it('con el permiso de 2 partes sale en 2 y el costo cuenta las dos; sin él, la plantilla no sale', function () {
    $long = '{{ contacto.nombre }}: '.str_repeat('a', 170);
    $blocked = smsTemplate($this, $long);
    expect(sendSms($this, $blocked->id)->reason)->toBe('sms_too_long');

    $allowed = smsTemplate($this, $long, true);
    expect(sendSms($this, $allowed->id)->status)->toBe(DispatchResult::QUEUED);
    $message = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->sole());
    expect($message->payload['sms_parts'])->toBe(2)
        ->and((float) $message->cost_estimated)->toEqualWithDelta(2 * 0.0592, 0.00001)
        ->and(twilioBodies()[0])->toStartWith('Ana: ');
});

it('el aviso sin plantilla puede pedir 2 partes y un SMS corto cuesta una', function () {
    expect(sendSms($this, null, ['text' => str_repeat('a', 200), 'sms_allow_multipart' => true])->status)->toBe(DispatchResult::QUEUED)
        ->and(sendSms($this, null, ['text' => 'Tu pedido va en camino'])->status)->toBe(DispatchResult::QUEUED);
    $costs = app(CurrentWorkspace::class)->run($this->ws, fn () => Message::query()->pluck('cost_estimated')->map(fn ($c) => round((float) $c, 4))->sort()->values()->all());
    expect($costs)->toBe([0.0592, 0.1184]);
});
