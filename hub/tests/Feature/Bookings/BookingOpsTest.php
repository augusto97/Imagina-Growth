<?php

use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Listeners\AppointmentReply;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/BookingFixtures.php';

/*
 * Lo que pasa después de agendar (M18): recordatorios de 24 h y 2 h (una vez),
 * la no asistencia automática, la sincronización con Google Calendar y las
 * respuestas «Confirmo» / «Reprogramar» por WhatsApp.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    [$this->site] = $this->connectedSite($this->ws);
    $this->fake = FakeMessageDispatcher::install();
    bookingFakeLeads();
    $this->service = bookingService($this->ws);
    bookingHours($this->ws);
    $this->contact = bookingContact($this->ws);
});

function bookingBook($test, string $startUtc, ?string $staffId = null): Appointment
{
    return bookingIn($test->ws, fn () => app(BookAppointment::class)->handle($test->ws, $test->service, CarbonImmutable::parse($startUtc), $staffId, $test->contact->id));
}

function bookingTick(string $at): void
{
    test()->artisan('igr:bookings:tick', ['--now' => CarbonImmutable::parse($at, 'America/Bogota')->toIso8601String()])->assertSuccessful();
}

it('manda el recordatorio de 24 h y el de 2 h una sola vez', function () {
    Queue::fake();
    $a = bookingBook($this, '2026-12-01T15:00:00Z'); // martes 10:00 Bogotá
    $this->fake->dispatched = [];

    bookingTick('2026-11-30 09:00');                 // 25 h antes: nada
    expect($this->fake->dispatched)->toBe([]);
    bookingTick('2026-11-30 10:05');                 // < 24 h
    bookingTick('2026-11-30 10:10');                 // repetido: no sale otra vez
    expect($this->fake->dispatched)->toHaveCount(1)->and($this->fake->dispatched[0]->kind)->toBe(MessageKind::Utility)
        ->and($this->fake->dispatched[0]->idempotencyKey)->toContain(':reminder_24:');
    bookingTick('2026-12-01 08:05');                 // < 2 h
    bookingTick('2026-12-01 08:30');
    expect($this->fake->dispatched)->toHaveCount(2)->and($this->fake->dispatched[1]->idempotencyKey)->toContain(':reminder_2:');
    expect(array_keys((array) $a->refresh()->reminders))->toBe(['reminder_24', 'reminder_2']);
});

it('no recuerda citas canceladas ni la de 24 h si ya faltan menos de 3 horas', function () {
    Queue::fake();
    $late = bookingBook($this, '2026-11-30T15:00:00Z');    // hoy 10:00
    $cancelled = bookingBook($this, '2026-12-01T16:00:00Z');
    bookingIn($this->ws, fn () => $cancelled->forceFill(['status' => 'cancelled'])->save());
    $this->fake->dispatched = [];
    bookingTick('2026-11-30 08:00'); // la de hoy: solo el de 2 h (se agendó hace más de 30 min… no: se agendó a las 7:00)
    $keys = collect($this->fake->dispatched)->pluck('idempotencyKey')->all();
    expect($keys)->toHaveCount(1)->and($keys[0])->toContain($late->id.':reminder_2:');
});

it('marca la no asistencia 30 minutos después del final y emite el evento', function () {
    Queue::fake();
    $a = bookingBook($this, '2026-11-30T15:00:00Z');       // 10:00–11:00
    $b = bookingBook($this, '2026-11-30T16:00:00Z');       // 11:00–12:00
    bookingIn($this->ws, fn () => app(ChangeAppointment::class)->mark($this->ws, $b, 'completed'));

    bookingTick('2026-11-30 11:20');
    expect($a->refresh()->status)->toBe('booked');
    bookingTick('2026-11-30 11:31');
    expect($a->refresh()->status)->toBe('no_show')->and($b->refresh()->status)->toBe('completed');
    $events = collect($this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event IN ('appointment.no_show', 'appointment.completed')"))->pluck('event')->sort()->values()->all();
    expect($events)->toBe(['appointment.completed', 'appointment.no_show']);

    // Apagada la no asistencia automática, se queda como está.
    $c = bookingBook($this, '2026-12-01T15:00:00Z');
    bookingIn($this->ws, fn () => BookingSettings::save($this->ws, ['auto_no_show' => false]));
    bookingTick('2026-12-01 12:00');
    expect($c->refresh()->status)->toBe('booked');
});

it('crea, mueve y borra el evento en el Google Calendar de la persona', function () {
    config(['igr-bookings.google.client_id' => 'cid', 'igr-bookings.google.client_secret' => 'secret']);
    Http::fake([
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt123']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt123' => Http::response(['id' => 'evt123']),
    ]);
    $laura = bookingStaff($this->ws, ['calendar' => ['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => now()->getTimestamp() + 3600, 'calendar_id' => 'primary']]);
    expect(json_decode((string) DB::table('booking_staff')->where('id', $laura->id)->value('calendar'), true))->toHaveKey('enc')
        ->and((string) DB::table('booking_staff')->where('id', $laura->id)->value('calendar'))->not->toContain('tok');

    $a = bookingBook($this, '2026-11-30T15:00:00Z', $laura->id); // la cola es síncrona en pruebas
    expect($a->refresh()->external_calendar_event_id)->toBe('evt123');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/events') && $r['summary'] === 'Valoración inicial'
        && ! str_contains(json_encode($r->data()), 'ana@example.com') && $r['start']['dateTime'] === '2026-11-30T15:00:00Z');

    bookingIn($this->ws, fn () => app(ChangeAppointment::class)->reschedule($this->ws, $a, CarbonImmutable::parse('2026-11-30T16:00:00Z'), 'staff'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/events/evt123'));
    bookingIn($this->ws, fn () => app(ChangeAppointment::class)->cancel($this->ws, $a->refresh(), 'staff'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/evt123'));
    expect($a->refresh()->external_calendar_event_id)->toBeNull();
});

it('sin Google configurado no sincroniza ni deja conectar', function () {
    Http::fake();
    config(['igr-bookings.google.client_id' => '', 'igr-bookings.google.client_secret' => '']);
    $laura = bookingStaff($this->ws);
    bookingBook($this, '2026-11-30T15:00:00Z', $laura->id);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'googleapis'));
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/bookings/staff/{$laura->publicId()}/google")->assertStatus(422);
});

it('conecta el calendario por OAuth con un state cifrado que vence', function () {
    config(['igr-bookings.google.client_id' => 'cid', 'igr-bookings.google.client_secret' => 'secret']);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600])]);
    $laura = bookingStaff($this->ws);
    $url = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/bookings/staff/{$laura->publicId()}/google")->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect($q['client_id'])->toBe('cid')->and($q['access_type'] ?? null)->toBe('offline')->and($q['scope'])->toContain('calendar');

    $this->get('/p/_google/calendar/callback?state=basura&code=x')->assertRedirect();
    $this->get('/p/_google/calendar/callback?state='.urlencode($q['state']).'&code=c0de')
        ->assertRedirect(rtrim((string) config('app.url'), '/')."/w/{$this->ws->publicId()}/agenda?pestana=personas&google=ok");
    $laura = bookingIn($this->ws, fn () => BookingStaff::query()->findOrFail($laura->id));
    expect($laura->calendarConnected())->toBeTrue()->and($laura->calendar['refresh_token'])->toBe('rt');
    Http::assertSent(fn (Request $r) => $r['code'] === 'c0de' && $r['grant_type'] === 'authorization_code');

    $this->travel(2)->hours();
    $this->get('/p/_google/calendar/callback?state='.urlencode($q['state']).'&code=otra')->assertRedirect(rtrim((string) config('app.url'), '/').'/');
});

it('«Confirmo» por WhatsApp confirma la próxima cita y «Reprogramar» manda el enlace', function () {
    Queue::fake();
    $a = bookingBook($this, '2026-12-01T15:00:00Z');
    $this->fake->dispatched = [];
    $inbound = function (array $payload) {
        $m = bookingIn($this->ws, fn () => Message::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel' => 'whatsapp',
            'provider' => 'cloud_api', 'direction' => 'in', 'source_type' => 'inbox', 'kind' => 'service', 'status' => 'delivered', 'payload' => $payload]));
        app(AppointmentReply::class)->handle(new InboundMessageReceived($this->ws->id, $m->id, null, $this->contact->id, false));
    };

    $inbound(['type' => 'text', 'text' => 'hola, ¿tienen parqueadero?']);
    expect($a->refresh()->status)->toBe('booked')->and($this->fake->dispatched)->toBe([]);

    $inbound(['type' => 'text', 'text' => 'Reprogramar']);
    expect($this->fake->dispatched)->toHaveCount(1)->and($this->fake->dispatched[0]->kind)->toBe(MessageKind::Service)
        ->and($this->fake->dispatched[0]->context['text'])->toContain('/cita/'.$a->manage_token.'?reprogramar=1');

    $inbound(['type' => 'button', 'text' => 'Sí, confirmo', 'button_payload' => 'appt_confirm']);
    expect($a->refresh()->status)->toBe('confirmed');
});

it('es del espacio de trabajo: otro no ve ni cambia sus citas', function () {
    Queue::fake();
    $a = bookingBook($this, '2026-11-30T15:00:00Z');
    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otro');
    app(CurrentWorkspace::class)->run($otherWs, function () use ($a) {
        expect(Appointment::query()->count())->toBe(0)->and(Appointment::findByPublicId($a->publicId()))->toBeNull()
            ->and(BookingStaff::query()->count())->toBe(0);
    });
    $this->actingAs($other)->postJson("/api/w/{$otherWs->publicId()}/bookings/appointments/{$a->publicId()}/cancel")->assertNotFound();
    $this->actingAs($other)->getJson("/api/w/{$this->ws->publicId()}/bookings")->assertNotFound();
    expect($a->refresh()->status)->toBe('booked');
});
