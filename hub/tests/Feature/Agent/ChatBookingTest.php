<?php

use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Chat\ChatBookingIds;
use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Leads\Jobs\ExtractLeadBrief;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Services\Leads\Models\Lead;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

require_once __DIR__.'/../Bookings/BookingFixtures.php';

/*
 * «Atiende el fin de semana» (auditoría §5.1 función 4): el agente ofrece horarios de la
 * agenda dentro del chat de WhatsApp como lista o botones, el cliente toca uno y la cita
 * queda con las reglas de la agenda, en su lead y en la cola del calendario; y la mueve o
 * la cancela cuando lo pide. De punta a punta: webhook de Meta, agente con el modelo
 * simulado, despachador real y la Cloud API simulada, con el reloj simulado.
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    // Sábado 21 de noviembre de 2026, 9:30 p. m. en Bogotá: no hay nadie del equipo.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 21:30:00', 'America/Bogota'));
    [$this->site] = $this->connectedSite($this->ws);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    Queue::fake([SyncAppointmentCalendar::class, ExtractLeadBrief::class, QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    $this->graph = [];
    Http::fake(['graph.test/*' => function (HttpRequest $request) {
        $this->graph[] = json_decode($request->body(), true);

        return Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(8)]]]);
    }]);
    ($this->in)(fn () => AgentSettings::query()->create(['workspace_id' => $this->ws->id, 'enabled' => true, 'default_mode' => 'auto', 'max_steps' => 6,
        'policies' => [], 'handoff_rules' => AgentSettings::defaultHandoff(), 'discount_rules' => ['enabled' => false]]));
    $this->service = bookingService($this->ws);
    bookingHours($this->ws); // lunes a viernes de 9:00 a 12:00
});

/** @param array<string, mixed> $message */
function chatWebhook($test, array $message, string $from = '573001234567')
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111'],
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ana']]],
        'messages' => [['from' => $from, 'id' => 'wamid.IN'.Str::random(10), 'timestamp' => (string) now()->getTimestamp()] + $message],
    ]]]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $body, 'meta-app-secret'),
    ], $body)->assertOk();
}

function chatSays($test, string $text, string $from = '573001234567'): void
{
    chatWebhook($test, ['type' => 'text', 'text' => ['body' => $text]], $from);
}

/** El cliente toca una opción de la lista o un botón. */
function chatTaps($test, string $id, string $title, string $kind = 'list_reply', string $from = '573001234567'): void
{
    chatWebhook($test, ['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => $title]]], $from);
}

/** @return list<array<string, mixed>> lo último que salió por la Cloud API */
function chatLastOut($test): array
{
    return end($test->graph) ?: [];
}

it('el sábado en la noche ofrece horarios del lunes en una lista, el cliente toca uno y la cita queda en la agenda, el lead y el calendario', function () {
    $ai = FakeAiGateway::install()
        ->callTool('list_booking_services', [])
        ->callTool('offer_appointment_slots', ['service_id' => $this->service->publicId(), 'date' => null, 'part_of_day' => null, 'time' => null])
        ->reply('¡Claro, Ana! Estos son los horarios libres para tu valoración. Toca el que prefieras.');
    chatSays($this, 'Hola, quiero agendar una valoración');

    $out = chatLastOut($this);
    expect($out['type'])->toBe('interactive')->and($out['interactive']['type'])->toBe('list')
        ->and($out['interactive']['body']['text'])->toContain('Toca el que prefieras')
        ->and(mb_strlen($out['interactive']['action']['button']))->toBeLessThanOrEqual(20);
    $sections = $out['interactive']['action']['sections'];
    $rows = collect($sections)->flatMap(fn ($s) => $s['rows'])->values();
    // Lunes 23 (no el fin de semana, que está cerrado), hasta 3 por día y 10 en total, con los límites de Meta.
    expect($sections[0]['title'])->toBe('lunes 23 de nov.')
        ->and($rows)->toHaveCount(10)
        ->and($rows[0]['title'])->toBe('9:00 am')->and($rows[1]['title'])->toBe('10:00 am')
        ->and($rows->every(fn ($r) => mb_strlen($r['title']) <= 24 && mb_strlen($r['description']) <= 72 && str_starts_with($r['id'], ChatBookingIds::PREFIX)))->toBeTrue();
    // El mensaje queda en la conversación con las opciones escritas (la historia que lee el agente).
    $conversation = ($this->in)(fn () => Conversation::query()->firstOrFail());
    $outbound = ($this->in)(fn () => Message::query()->where('direction', 'out')->where('conversation_id', $conversation->id)->latest('created_at')->firstOrFail());
    expect($outbound->payload['text'])->toContain('lunes 23 de nov. · 10:00 am')->and($outbound->kind->value)->toBe('service');

    // El cliente toca el lunes a las 10:00: se agenda sin pasar por el modelo.
    $calls = count($ai->requests);
    chatTaps($this, $rows[1]['id'], $rows[1]['title']);
    $appointment = ($this->in)(fn () => Appointment::query()->sole());
    expect(CarbonImmutable::instance($appointment->starts_at)->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-23 10:00')
        ->and($appointment->channel)->toBe('agent')->and($appointment->status)->toBe('booked')
        ->and($appointment->contact_id)->toBe($conversation->contact_id)
        ->and(count($ai->requests))->toBe($calls);
    $lead = ($this->in)(fn () => Lead::query()->where('contact_id', $conversation->contact_id)->sole());
    expect($appointment->lead_id)->toBe($lead->id)->and($lead->service)->toBe('Valoración inicial');
    Queue::assertPushed(SyncAppointmentCalendar::class, fn ($job) => $job->appointmentId === $appointment->id);
    $confirmation = chatLastOut($this);
    expect($confirmation['type'])->toBe('text')
        ->and($confirmation['text']['body'])->toContain('¡Listo! Tu cita quedó agendada.')->toContain('lunes 23 de noviembre a las 10:00 am')
        ->and($confirmation['text']['body'])->toContain('https://go.test/c/'); // el enlace de la cita, rastreado

    // Tocar dos veces no agenda dos veces.
    chatTaps($this, $rows[1]['id'], $rows[1]['title']);
    expect(($this->in)(fn () => Appointment::query()->count()))->toBe(1)
        ->and(chatLastOut($this)['text']['body'])->toContain('Ya tienes esa cita agendada.');
});

it('la mueve al día que pide con botones y la cancela solo cuando el cliente lo confirma', function () {
    $contact = $this->contact;
    $appointment = bookingIn($this->ws, fn () => app(BookAppointment::class)->handle($this->ws, $this->service,
        CarbonImmutable::parse('2026-11-23 10:00', 'America/Bogota'), null, $contact->id, 'agent'));
    // Domingo: pide moverla para el martes.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 18:00:00', 'America/Bogota'));
    FakeAiGateway::install()
        ->callTool('my_appointments', [])
        ->callTool('reschedule_appointment', ['appointment_id' => $appointment->publicId(), 'date' => '2026-11-24', 'part_of_day' => null])
        ->reply('Claro, el martes tengo estos horarios. Elige uno:');
    chatSays($this, '¿La puedo pasar para el martes?');
    $out = chatLastOut($this);
    $buttons = $out['interactive']['action']['buttons'];
    expect($out['interactive']['type'])->toBe('button')->and($buttons)->toHaveCount(3)
        ->and(collect($buttons)->pluck('reply.title')->all())->toBe(['mar 24 · 9:00 am', 'mar 24 · 10:00 am', 'mar 24 · 11:00 am'])
        ->and(collect($buttons)->every(fn ($b) => mb_strlen($b['reply']['title']) <= 20 && str_starts_with($b['reply']['id'], 'bk1:r:')))->toBeTrue();

    chatTaps($this, $buttons[2]['reply']['id'], $buttons[2]['reply']['title'], 'button_reply');
    $moved = ($this->in)(fn () => $appointment->fresh());
    expect($moved->status)->toBe('rescheduled')
        ->and(CarbonImmutable::instance($moved->starts_at)->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-24 11:00')
        ->and(chatLastOut($this)['text']['body'])->toContain('¡Listo! Movimos tu cita.')->toContain('martes 24 de noviembre a las 11:00 am');

    // Pide cancelar: primero confirma con un botón; el modelo nunca cancela solo.
    FakeAiGateway::install()
        ->callTool('my_appointments', [])
        ->callTool('cancel_appointment', ['appointment_id' => $appointment->publicId()])
        ->reply('Entendido. ¿Confirmas que cancelamos tu valoración del martes?');
    chatSays($this, 'Mejor cancélala, por favor');
    $out = chatLastOut($this);
    expect($out['interactive']['type'])->toBe('button')
        ->and(collect($out['interactive']['action']['buttons'])->pluck('reply.title')->all())->toBe(['Sí, cancelar', 'No, mantenerla'])
        ->and(($this->in)(fn () => $appointment->fresh()->status))->toBe('rescheduled');

    chatTaps($this, ChatBookingIds::keep($appointment->id), 'No, mantenerla', 'button_reply');
    expect(($this->in)(fn () => $appointment->fresh()->status))->toBe('rescheduled')
        ->and(chatLastOut($this)['text']['body'])->toContain('tu cita sigue en pie');

    chatTaps($this, ChatBookingIds::cancel($appointment->id), 'Sí, cancelar', 'button_reply');
    expect(($this->in)(fn () => $appointment->fresh()->status))->toBe('cancelled')
        ->and(chatLastOut($this)['text']['body'])->toContain('cancelamos tu cita de Valoración inicial');
});

it('si otro cliente tomó el horario mientras elegía, ofrece los que siguen libres y no cruza citas', function () {
    $start = CarbonImmutable::parse('2026-11-23 09:00', 'America/Bogota');
    $other = bookingContact($this->ws, ['first_name' => 'Luis', 'email' => 'luis@example.com', 'phone' => '+573009998877']);
    bookingIn($this->ws, fn () => app(BookAppointment::class)->handle($this->ws, $this->service, $start, null, $other->id, 'page'));

    chatTaps($this, ChatBookingIds::book($this->service->id, $start), 'lun 23 · 9:00 am');
    $out = chatLastOut($this);
    expect(($this->in)(fn () => Appointment::query()->count()))->toBe(1)
        ->and($out['type'])->toBe('interactive')->and($out['interactive']['body']['text'])->toContain('Ese horario se acaba de ocupar')
        ->and(collect($out['interactive']['action']['buttons'] ?? $out['interactive']['action']['sections'][0]['rows'] ?? [])->toJson())->not->toContain((string) $start->getTimestamp());
});

it('fuera de la ventana de 24 h no ofrece nada, y un servicio de otro espacio de trabajo no se agenda', function () {
    // Un toque con el ID de un servicio de otro negocio: no agenda nada aquí.
    $foreign = $this->workspaceFor($this->userWith2fa(), 'Otra clínica');
    $foreignService = bookingService($foreign);
    chatTaps($this, ChatBookingIds::book($foreignService->id, CarbonImmutable::parse('2026-11-23 09:00', 'America/Bogota')), 'lun 23 · 9:00 am');
    expect(Appointment::withoutWorkspaceScope()->count())->toBe(0)
        ->and(chatLastOut($this)['text']['body'])->toContain('Ese servicio ya no se puede agendar por aquí');

    // Con la ventana cerrada el agente no responde (pasa a una persona) y no sale ninguna lista.
    $conversation = ($this->in)(fn () => Conversation::query()->firstOrFail());
    ($this->in)(fn () => $conversation->forceFill(['window_expires_at' => now()->subMinute()])->save());
    $before = count($this->graph);
    $ai = FakeAiGateway::install();
    $inbound = ($this->in)(fn () => Message::query()->create([
        'workspace_id' => $this->ws->id, 'contact_id' => $conversation->contact_id, 'channel_account_id' => $this->wa->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
        'provider_message_id' => 'wamid.late', 'direction' => 'in', 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
        'payload' => ['type' => 'text', 'text' => 'quiero una cita'], 'conversation_id' => $conversation->id,
    ]));
    ($this->in)(fn () => app(ConversationAgent::class)->onInbound($conversation->fresh(), $inbound));
    expect($ai->requests)->toBe([])->and(count($this->graph))->toBe($before);
});
