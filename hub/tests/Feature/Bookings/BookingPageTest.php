<?php

use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/BookingFixtures.php';

/*
 * Página pública de reservas (M18): horarios, captura del contacto con sus
 * consentimientos, cita sin doble reserva, lead, evento, confirmación y la
 * página para confirmar, reprogramar o cancelar con su .ics.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([SyncAppointmentCalendar::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    [$this->site] = $this->connectedSite($this->ws);
    $this->fake = FakeMessageDispatcher::install();
    $this->leads = bookingFakeLeads();
    $this->service = bookingService($this->ws);
    bookingHours($this->ws);
    $this->base = '/p/'.$this->site->public_key;
});

function bookingForm(array $overrides = []): array
{
    return $overrides + ['start' => '2026-11-30T15:00:00Z', 'name' => 'Ana María Ruiz', 'phone' => '300 123 4567', 'email' => 'ana@example.com',
        'consent' => ['whatsapp' => '1'], 'utm_source' => 'google', 'gclid' => 'abc'];
}

it('muestra los días y horarios libres en la hora del negocio', function () {
    $res = $this->get("{$this->base}/reservar")->assertOk(); // un solo servicio: va directo
    $res->assertSee('Valoración inicial')->assertSee('9:00 am')->assertSee('11:00 am')->assertSee('Horas de Bogotá')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex');
    expect(substr_count($res->getContent(), 'name="start"'))->toBe(9);

    $this->get("{$this->base}/reservar/valoracion?dia=2026-12-01")->assertOk()->assertSee('Martes 1 de diciembre');
    $this->get("{$this->base}/reservar/no-existe")->assertNotFound();
    $this->get('/p/pk_'.str_repeat('0', 32).'/reservar')->assertNotFound();
    // También con el ID público del espacio de trabajo.
    $this->get('/p/'.$this->ws->publicId().'/reservar/valoracion')->assertOk()->assertSee('9:00 am');
});

it('con varios servicios lista los servicios', function () {
    bookingService($this->ws, ['name' => 'Control', 'slug' => 'control', 'price' => null]);
    $this->get("{$this->base}/reservar?utm_source=ig")->assertOk()->assertSee('¿Qué servicio necesitas?')
        ->assertSee('/reservar/control?utm_source=ig', false)->assertSee('$80.000 COP');
});

it('agenda la cita: contacto, consentimiento, lead, evento y confirmación', function () {
    $res = $this->post("{$this->base}/reservar/valoracion", bookingForm());
    $appointment = bookingIn($this->ws, fn () => Appointment::query()->firstOrFail());
    $res->assertStatus(303)->assertRedirect("{$this->base}/cita/{$appointment->manage_token}?ok=nueva");

    $contact = bookingIn($this->ws, fn () => Contact::query()->findOrFail($appointment->contact_id));
    expect($contact->first_name)->toBe('Ana')->and($contact->last_name)->toBe('María Ruiz')->and($contact->phone)->toBe('+573001234567')
        ->and($appointment->starts_at->toIso8601ZuluString())->toBe('2026-11-30T15:00:00Z')
        ->and($appointment->ends_at->toIso8601ZuluString())->toBe('2026-11-30T16:00:00Z')
        ->and($appointment->status)->toBe('booked')->and($appointment->channel)->toBe('page')->and($appointment->lead_id)->toHaveLength(26);

    $consents = DB::table('contact_consents')->where('contact_id', $contact->id)->where('status', 'granted')->pluck('channel')->all();
    expect($consents)->toBe(['whatsapp']); // el correo no se marcó

    expect($this->leads->captured)->toHaveCount(1);
    $lead = $this->leads->captured[0];
    expect($lead->origin)->toBe('booking')->and($lead->originRef)->toBe($appointment->publicId())->and($lead->service)->toBe('Valoración inicial')
        ->and($lead->value)->toBe('80000.00')->and($lead->source)->toMatchArray(['utm_source' => 'google', 'gclid' => 'abc'])->and($lead->siteId)->toBe($this->site->id);

    $rows = $this->chRows($this->ws, "SELECT event, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'appointment.booked'");
    expect($rows)->toHaveCount(1);
    $props = json_decode($rows[0]['p'], true);
    expect($props['service_name'])->toBe('Valoración inicial')->and(str_replace(['T', 'Z'], [' ', ''], (string) $props['starts_at']))->toStartWith('2026-11-30 15:00:00')
        ->and($props['lead_id'])->toStartWith('ld_')->and($rows[0]['p'])->not->toContain('ana@example.com')->not->toContain('3001234567');

    // Sin plantilla de WhatsApp elegida, la confirmación va por correo y es transaccional.
    expect($this->fake->dispatched)->toHaveCount(1);
    $msg = $this->fake->dispatched[0];
    expect($msg->channel)->toBe(Channel::Email)->and($msg->kind)->toBe(MessageKind::Transactional)
        ->and($msg->context['subject'])->toContain('Valoración inicial')
        ->and($msg->context['html'])->toContain('/cita/'.$appointment->manage_token)->toContain('/calendario.ics');
    Queue::assertPushed(SyncAppointmentCalendar::class);
});

it('no deja reservar dos veces el mismo horario', function () {
    $this->post("{$this->base}/reservar/valoracion", bookingForm())->assertStatus(303);
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['phone' => '3009998877', 'email' => 'otro@example.com']))
        ->assertStatus(409)->assertSee('Ese horario ya no está disponible');
    // Se solapa con la primera (15:30 UTC = 10:30 Bogotá): tampoco.
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['start' => '2026-11-30T15:30:00Z', 'phone' => '3009998877']))->assertStatus(409);
    // Fuera del horario o en un minuto que no es un horario ofrecido: tampoco.
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['start' => '2026-11-30T22:00:00Z', 'phone' => '3009998877']))->assertStatus(409);
    expect(bookingIn($this->ws, fn () => Appointment::query()->count()))->toBe(1);
});

it('con dos personas el mismo horario se reserva dos veces y la tercera no', function () {
    $laura = bookingStaff($this->ws);
    $pedro = bookingStaff($this->ws, ['name' => 'Pedro Pérez']);
    $this->get("{$this->base}/reservar/valoracion")->assertSee('¿Con quién?')->assertSee('Pedro Pérez');

    $this->post("{$this->base}/reservar/valoracion", bookingForm())->assertStatus(303);
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['phone' => '3009998877', 'email' => null]))->assertStatus(303);
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['phone' => '3105556677', 'email' => null]))->assertStatus(409);
    $staff = bookingIn($this->ws, fn () => Appointment::query()->pluck('staff_id')->sort()->values()->all());
    expect($staff)->toBe(collect([$laura->id, $pedro->id])->sort()->values()->all());
});

it('valida los datos y exige el correo si el negocio lo pide', function () {
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['name' => '', 'phone' => '', 'email' => '']))
        ->assertStatus(422)->assertSee('Revisa los campos marcados');
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['phone' => '12', 'email' => null]))->assertStatus(422);

    bookingIn($this->ws, fn () => BookingSettings::save($this->ws, ['require_email' => true]));
    $this->post("{$this->base}/reservar/valoracion", bookingForm(['email' => null]))->assertStatus(422);
    expect(bookingIn($this->ws, fn () => Appointment::query()->count()))->toBe(0);
});

it('limita los intentos por IP', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post("{$this->base}/reservar/valoracion", ['start' => 'x'])->assertStatus(422);
    }
    $this->post("{$this->base}/reservar/valoracion", bookingForm())->assertStatus(429);
});

it('la persona confirma, reprograma y cancela desde su enlace', function () {
    $this->post("{$this->base}/reservar/valoracion", bookingForm())->assertStatus(303);
    $a = bookingIn($this->ws, fn () => Appointment::query()->firstOrFail());
    $url = "{$this->base}/cita/{$a->manage_token}";

    $this->get($url.'?ok=nueva')->assertOk()->assertSee('Tu cita quedó agendada')->assertSee('Lunes 30 de noviembre')->assertSee('10:00 am')->assertSee('Confirmo');
    $this->post("{$url}/confirmar")->assertStatus(303)->assertRedirect("{$url}?ok=confirmada");
    expect($a->refresh()->status)->toBe('confirmed');

    // Reprogramar: el horario propio no se bloquea a sí mismo (10:15 se solapa con 10:00).
    $this->get("{$url}?reprogramar=1")->assertOk()->assertSee('Elige el nuevo horario')->assertSee('10:15 am');
    $this->post("{$url}/reprogramar", ['start' => '2026-11-30T15:15:00Z'])->assertStatus(303)->assertRedirect("{$url}?ok=reprogramada");
    $a->refresh();
    expect($a->status)->toBe('rescheduled')->and($a->starts_at->toIso8601ZuluString())->toBe('2026-11-30T15:15:00Z');
    $this->post("{$url}/reprogramar", ['start' => '2026-11-30T23:00:00Z'])->assertStatus(409)->assertSee('Ese horario ya no está disponible');

    $ics = $this->get("{$url}/calendario.ics")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain('DTSTART:20261130T151500Z')->toContain('DTEND:20261130T161500Z')->toContain('SUMMARY:Valoración inicial')
        ->toContain('UID:'.$a->publicId().'@');

    $this->post("{$url}/cancelar")->assertStatus(303);
    expect($a->refresh()->status)->toBe('cancelled');
    $this->post("{$url}/confirmar")->assertStatus(409);
    expect(str_contains($this->get("{$url}/calendario.ics")->getContent(), 'STATUS:CANCELLED'))->toBeTrue();

    $events = collect($this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event LIKE 'appointment.%'"))->pluck('event')->sort()->values()->all();
    expect($events)->toBe(['appointment.booked', 'appointment.cancelled', 'appointment.rescheduled']);
    $kinds = collect($this->fake->dispatched)->map(fn ($m) => $m->idempotencyKey)->map(fn ($k) => explode(':', (string) $k)[2])->all();
    expect($kinds)->toBe(['confirmation', 'rescheduled', 'cancelled']);
});

it('un token ajeno o de otro negocio no abre la cita', function () {
    $this->post("{$this->base}/reservar/valoracion", bookingForm())->assertStatus(303);
    $a = bookingIn($this->ws, fn () => Appointment::query()->firstOrFail());
    $other = $this->workspaceFor($this->userWith2fa(), 'Otro');
    [$otherSite] = $this->connectedSite($other, 'otro.co');

    $this->get("/p/{$otherSite->public_key}/cita/{$a->manage_token}")->assertNotFound();
    $this->get("{$this->base}/cita/".str_repeat('x', 40))->assertNotFound();
    $this->post("/p/{$otherSite->public_key}/cita/{$a->manage_token}/cancelar")->assertNotFound();
    expect($a->refresh()->status)->toBe('booked');
});

it('sin sitio conectado la página se ve pero no recibe reservas', function () {
    $ws = $this->workspaceFor($this->userWith2fa(), 'Sin sitio');
    bookingService($ws);
    bookingHours($ws);
    $this->get('/p/'.$ws->publicId().'/reservar/valoracion')->assertOk()->assertSee('todavía no puede recibir reservas');
    $this->post('/p/'.$ws->publicId().'/reservar/valoracion', bookingForm())->assertStatus(422);
});
