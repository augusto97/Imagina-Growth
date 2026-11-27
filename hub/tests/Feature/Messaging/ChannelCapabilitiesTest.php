<?php

use App\Domain\Bookings\Actions\AppointmentNotifier;
use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Channels\ImaginaWhats\ImaginaWhatsSignature;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\NumberedOptions;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use App\Domain\Services\Leads\Jobs\ExtractLeadBrief;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Data\RenderedMessage;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

require_once __DIR__.'/../Bookings/BookingFixtures.php';

/*
 * Lo que puede cada WhatsApp (M07, ChannelCapabilities): Imagina Whats y Zender no muestran botones, listas ni
 * plantillas de Meta. Las opciones salen numeradas y la respuesta escrita vuelve como el botón; las plantillas se
 * componen como texto con sus enlaces y respuestas rápidas, y el archivo del encabezado va aparte si el proveedor lo
 * manda. Los avisos de la agenda salen como texto, y el agente agenda con horarios numerados.
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    config(['igr-messaging.imagina_whats.url' => 'https://whats.test']);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    Http::fake([
        'whats.test/*' => fn (HttpRequest $r) => Http::response(['data' => ['id' => random_int(1000, 999999), 'status' => 'queued', 'reference' => $r['reference'] ?? null]], 202),
        'zender.test/*' => Http::response(['status' => 200, 'data' => ['messageId' => 77]]),
        'graph.test/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(8)]]]),
    ]);
    $this->iwa = ($this->in)(fn () => ChannelAccount::query()->create([
        'workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'imagina_whats', 'display_name' => 'Ventas', 'status' => 'active',
        'external_id' => '7', 'identity' => '+573105550000', 'config' => ['api_key' => 'iw-clave', 'wa_account_id' => '7', 'webhook_id' => '31', 'webhook_secret' => 'sec-iw'],
    ]));
});

/** Imagina Whats manda uno por segundo por cuenta: la prueba no espera. */
function capNextSecond($test): void
{
    Redis::connection()->del('ws:'.$test->ws->id.':send:'.$test->iwa->id);
}

/** @return list<array<string, mixed>> lo que se le pidió enviar a Imagina Whats */
function capIwSent(): array
{
    return array_values(array_map(fn (array $pair) => $pair[0]->data(), Http::recorded(fn (HttpRequest $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/messages/whatsapp')->all()));
}

/** El cliente escribe por Imagina Whats (webhook firmado). */
function capIwSays($test, string $text, ?ChannelAccount $account = null, string $from = '+573001234567'): void
{
    $account ??= $test->iwa;
    $body = json_encode(['id' => (string) Str::uuid(), 'event' => 'wa.message.received', 'sent_at' => now()->toIso8601String(), 'payload' => [
        'id' => random_int(1, 99999999), 'direction' => 'in', 'from' => $from, 'to' => '+573105550000', 'body' => $text,
        'type' => 'text', 'status' => 'received', 'attachments' => [], 'created_at' => now()->toIso8601String(),
    ]]);
    $test->call('POST', '/webhooks/imagina-whats/'.$account->publicId(), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => ImaginaWhatsSignature::sign((string) $body, 'sec-iw'),
    ], $body)->assertOk();
}

function capLastInbound($test): Message
{
    return ($test->in)(fn () => Message::query()->where('direction', 'in')->orderByDesc('created_at')->orderByDesc('id')->firstOrFail());
}

/** @param array<string, mixed> $context */
function capDispatch($test, ChannelAccount $account, array $context, MessageKind $kind = MessageKind::Service, ?string $templateId = null): DispatchResult
{
    return ($test->in)(fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $test->contact->id, Channel::WhatsApp, $kind, 'system', templateId: $templateId, channelAccountId: $account->id, context: $context,
        idempotencyKey: 'cap:'.Str::random(10),
    )));
}

it('cada proveedor dice qué puede enviar y la API de canales lo expone', function () {
    $registry = app(DriverRegistry::class);
    $meta = $registry->for('meta_cloud')->capabilities();
    $iw = $registry->for('imagina_whats')->capabilities();
    $zender = $registry->for('zender')->capabilities();
    expect($meta->official)->toBeTrue()->and($meta->buttons)->toBeTrue()->and($meta->lists)->toBeTrue()->and($meta->templates)->toBeTrue()
        ->and($meta->templateButtons)->toBeTrue()->and($meta->templateMediaHeader)->toBeTrue()->and($meta->optionsAs())->toBe('buttons')
        ->and($iw->official)->toBeFalse()->and($iw->buttons)->toBeFalse()->and($iw->lists)->toBeFalse()->and($iw->templates)->toBeFalse()
        ->and($iw->marketing)->toBeFalse()->and($iw->media)->toBe(['image', 'video', 'document', 'audio'])->and($iw->optionsAs())->toBe('numbered')
        ->and($zender->media)->toBe([])->and($zender->buttons)->toBeFalse()
        ->and($registry->for('meta_messenger')->capabilities()->maxText)->toBe(2000)
        ->and($registry->for('meta_instagram')->capabilities()->maxText)->toBe(1000);

    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/channels")->assertOk();
    $byProvider = collect($res->json('data'))->keyBy('provider');
    expect($byProvider['imagina_whats']['capabilities'])->toMatchArray(['buttons' => false, 'lists' => false, 'templates' => false, 'options_as' => 'numbered', 'media' => true])
        ->and($byProvider['meta_cloud']['capabilities'])->toMatchArray(['buttons' => true, 'template_buttons' => true, 'options_as' => 'buttons']);
});

it('una respuesta escrita elige una sola opción: el número, el texto o la hora', function () {
    $slots = NumberedOptions::fromInteractive(WhatsAppInteractive::list('Elige:', 'Ver horarios', [
        ['title' => 'lunes 23', 'rows' => [['id' => 'a', 'title' => '9:00 am'], ['id' => 'b', 'title' => '10:30 am']]],
        ['title' => 'martes 24', 'rows' => [['id' => 'c', 'title' => '9:00 am']]],
    ]));
    expect(array_column($slots, 'title'))->toBe(['lunes 23 · 9:00 am', 'lunes 23 · 10:30 am', 'martes 24 · 9:00 am'])
        ->and(NumberedOptions::match('2', $slots))->toBe('b')
        ->and(NumberedOptions::match(' 3. ', $slots))->toBe('c')
        ->and(NumberedOptions::match('Opción 1', $slots))->toBe('a')
        ->and(NumberedOptions::match('2️⃣', $slots))->toBe('b')
        ->and(NumberedOptions::match('a las 10:30 porfa', $slots))->toBe('b')
        ->and(NumberedOptions::match('9:00', $slots))->toBeNull() // está en dos días: no adivina
        ->and(NumberedOptions::match('4', $slots))->toBeNull()
        ->and(NumberedOptions::match('hola, ¿cuánto vale?', $slots))->toBeNull();

    $yesNo = NumberedOptions::fromInteractive(WhatsAppInteractive::buttons('¿Confirmas?', [['id' => 'si', 'title' => 'Sí, cancelar'], ['id' => 'no', 'title' => 'No, mantenerla']]));
    expect(NumberedOptions::match('si cancelar por favor', $yesNo))->toBe('si')
        ->and(NumberedOptions::match('No, mantenerla', $yesNo))->toBe('no')
        ->and(NumberedOptions::text('¿Confirmas?', $yesNo))->toBe("¿Confirmas?\n\n1. Sí, cancelar\n2. No, mantenerla\n\nResponde con el número de tu opción (1 a 2).");
});

it('sin botones las opciones salen numeradas y la respuesta escrita vuelve como si las hubiera tocado', function () {
    capIwSays($this, 'Hola'); // abre la ventana de 24 h de la conversación
    $interactive = WhatsAppInteractive::buttons('¿Qué día te sirve?', [['id' => 'dia_lun', 'title' => 'Lunes'], ['id' => 'dia_mar', 'title' => 'Martes']]);
    expect(capDispatch($this, $this->iwa, ['text' => WhatsAppInteractive::asText($interactive), 'interactive' => $interactive])->status)->toBe(DispatchResult::QUEUED);

    $sent = capIwSent();
    expect($sent)->toHaveCount(1)->and($sent[0]['type'])->toBe('text')
        ->and($sent[0]['body'])->toBe("¿Qué día te sirve?\n\n1. Lunes\n2. Martes\n\nResponde con el número de tu opción (1 a 2).");
    $out = ($this->in)(fn () => Message::query()->where('direction', 'out')->where('channel_account_id', $this->iwa->id)->sole());
    expect($out->payload)->not->toHaveKey('interactive')->and(array_column($out->payload['options'], 'id'))->toBe(['dia_lun', 'dia_mar']);

    capIwSays($this, '2');
    $in = capLastInbound($this);
    expect($in->payload['button_payload'])->toBe('dia_mar')->and($in->payload['option_reply'])->toBeTrue()->and($in->payload['text'])->toBe('2');

    // Después de otro mensaje sin opciones, un número suelto ya no elige nada.
    capDispatch($this, $this->iwa, ['text' => '¡Perfecto!']);
    capIwSays($this, '1');
    expect(capLastInbound($this)->payload)->not->toHaveKey('button_payload');

    // Con el WhatsApp oficial salen los botones de verdad.
    ($this->in)(fn () => Conversation::query()->create(['workspace_id' => $this->ws->id, 'channel_account_id' => $this->wa->id,
        'contact_id' => $this->contact->id, 'status' => 'open', 'last_inbound_at' => now(), 'window_expires_at' => now()->addDay()]));
    capDispatch($this, $this->wa, ['text' => WhatsAppInteractive::asText($interactive), 'interactive' => $interactive]);
    $graph = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.test'))->all();
    expect(end($graph)[0]->data()['type'])->toBe('interactive');
});

it('una plantilla con imagen, botón de enlace y respuestas rápidas sale como texto: la imagen aparte si el proveedor la manda', function () {
    app()->instance(TemplateRenderer::class, new class implements TemplateRenderer
    {
        public function render(string $templateId, RenderContext $context): RenderedMessage
        {
            return new RenderedMessage(waTemplateName: 'recordatorio_cita', waLanguage: 'es', waComponents: [
                ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.tienda.co/cita.jpg']]]],
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'ap_123']]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 1, 'parameters' => [['type' => 'payload', 'payload' => 'appt_confirm']]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 2, 'parameters' => [['type' => 'payload', 'payload' => 'appt_reschedule']]],
            ]);
        }
    });
    ($this->in)(fn () => WaTemplate::query()->create(['workspace_id' => $this->ws->id, 'channel_account_id' => $this->wa->id, 'name' => 'recordatorio_cita', 'language' => 'es',
        'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hola {{1}}, tu cita es mañana.'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Ver mi cita', 'url' => 'https://tienda.co/cita/{{1}}'],
                ['type' => 'QUICK_REPLY', 'text' => 'Confirmo'],
                ['type' => 'QUICK_REPLY', 'text' => 'Reprogramar'],
            ]],
        ]]));

    expect(capDispatch($this, $this->iwa, [], MessageKind::Utility, 'tpl_recordatorio')->status)->toBe(DispatchResult::QUEUED);
    $sent = capIwSent()[0];
    expect($sent['type'])->toBe('media')->and($sent['media_url'])->toBe('https://cdn.tienda.co/cita.jpg')
        ->and($sent['body'])->toStartWith("Hola Ana, tu cita es mañana.\n\nVer mi cita: https://go.test/c/")
        ->and($sent['body'])->toContain("1. Confirmo\n2. Reprogramar");
    capIwSays($this, '1');
    expect(capLastInbound($this)->payload['button_payload'])->toBe('appt_confirm');

    // Zender no manda archivos: la imagen se omite y queda anotado en el mensaje; el texto sale igual.
    $zender = ($this->in)(fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'zender', 'display_name' => 'Zender',
        'status' => 'active', 'config' => ['base_url' => 'https://zender.test', 'api_secret' => 'sec', 'account_id' => 'dev1']]));
    capDispatch($this, $zender, [], MessageKind::Utility, 'tpl_recordatorio');
    $message = ($this->in)(fn () => Message::query()->where('channel_account_id', $zender->id)->sole());
    expect($message->payload['degraded'])->toBe(['header_media'])->and($message->payload)->not->toHaveKey('media')
        ->and($message->status)->toBe('sent');
});

it('los avisos de la cita salen como texto por el WhatsApp no oficial y «Confirmo» la confirma', function () {
    ($this->in)(fn () => $this->wa->forceFill(['status' => 'disconnected'])->save()); // el único WhatsApp activo es Imagina Whats
    $service = bookingService($this->ws);
    bookingHours($this->ws);
    $appointment = ($this->in)(fn () => app(BookAppointment::class)->handle($this->ws, $service, CarbonImmutable::parse('2026-11-18 09:00', 'America/Bogota'), null, $this->contact->id, 'page'));
    // La confirmación de la reserva ya salió por Imagina Whats, como texto y con el enlace para gestionarla.
    $confirmation = collect(capIwSent())->last();
    expect($confirmation['type'])->toBe('text')->and($confirmation['body'])->toContain('quedó agendada')->toContain('https://go.test/c/');

    capNextSecond($this);
    $result = ($this->in)(fn () => app(AppointmentNotifier::class)->send($this->ws, $appointment, 'reminder_24'));
    expect($result?->status)->toBe(DispatchResult::QUEUED);
    $reminder = collect(capIwSent())->last();
    expect($reminder['type'])->toBe('text')->and($reminder['body'])->toContain('Te recordamos tu cita')->toContain('«Confirmo»')->toContain('«Reprogramar»');

    capNextSecond($this);
    capIwSays($this, 'Confirmo');
    expect(($this->in)(fn () => $appointment->fresh()->status))->toBe('confirmed')
        ->and(collect(capIwSent())->last()['body'])->toContain('Tu cita quedó confirmada');
});

it('el agente agenda por Imagina Whats con horarios numerados y la persona responde con el número', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:30:00', 'America/Bogota')); // sábado en la noche
    [$this->site] = $this->connectedSite($this->ws);
    Queue::fake([SyncAppointmentCalendar::class, ExtractLeadBrief::class, QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    ($this->in)(fn () => AgentSettings::query()->create(['workspace_id' => $this->ws->id, 'enabled' => true, 'default_mode' => 'auto', 'max_steps' => 6,
        'policies' => [], 'handoff_rules' => AgentSettings::defaultHandoff(), 'discount_rules' => ['enabled' => false]]));
    $service = bookingService($this->ws);
    bookingHours($this->ws);

    FakeAiGateway::install()
        ->callTool('offer_appointment_slots', ['service_id' => $service->publicId(), 'date' => null, 'part_of_day' => null, 'time' => null])
        ->reply('¡Claro! Estos son los horarios libres. Respóndeme con el número del que prefieras.');
    capIwSays($this, 'Hola, quiero agendar una valoración');

    $offer = collect(capIwSent())->last();
    expect($offer['type'])->toBe('text')->and($offer['body'])->toContain('Respóndeme con el número')
        ->and($offer['body'])->toContain('1. lunes 23 de nov. · 9:00 am')->toContain('2. lunes 23 de nov. · 10:00 am')
        ->and($offer['body'])->toContain('Responde con el número de tu opción');

    capNextSecond($this);
    capIwSays($this, '2');
    $appointment = ($this->in)(fn () => Appointment::query()->sole());
    expect(CarbonImmutable::instance($appointment->starts_at)->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-23 10:00')
        ->and($appointment->contact_id)->toBe($this->contact->id)
        ->and(collect(capIwSent())->last()['body'])->toContain('¡Listo! Tu cita quedó agendada.');
});

it('el chat con atribución pregunta por novedades con opciones numeradas y el «1» registra el consentimiento como respuesta escrita', function () {
    Queue::fake([ExtractLeadBrief::class, QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    $qr = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/capture/whatsapp-links", ['name' => 'Mostrador', 'message' => 'Hola, quiero recibir novedades'])
        ->assertCreated()->json();
    capIwSays($this, 'Hola, quiero recibir novedades (ref: '.$qr['code'].')', from: '+573009998877');

    $ask = collect(capIwSent())->last();
    expect($ask['type'])->toBe('text')->and($ask['to'])->toBe('+573009998877')
        ->and($ask['body'])->toContain('Al responder «1» o «Sí, quiero» aceptas')->toContain("1. Sí, quiero\n2. No, gracias");

    capNextSecond($this);
    capIwSays($this, '1', from: '+573009998877');
    $contact = ($this->in)(fn () => Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573009998877'))->sole());
    $consent = DB::table('contact_consents')->where('contact_id', $contact->id)->where('channel', 'whatsapp')->first();
    expect($consent->status)->toBe('granted')->and($consent->source)->toBe('whatsapp_reply');
    $evidence = json_decode((string) DB::table('consent_events')->where('contact_id', $contact->id)->value('evidence'), true);
    expect($evidence)->toMatchArray(['reply' => 'numbered_option', 'prompt' => 'whatsapp_link'])
        ->and(collect(capIwSent())->last()['body'])->toContain('responde «baja»');
});
