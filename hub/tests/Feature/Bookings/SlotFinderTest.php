<?php

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Support\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/BookingFixtures.php';

/*
 * Horarios libres (M18): zona horaria del negocio, margen entre citas,
 * anticipación mínima, máximo de días, excepciones, festivos del país,
 * horario propio de cada persona y lo ocupado en Google Calendar.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    $this->now = CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'); // lunes
    $this->service = bookingService($this->ws);
});

function bookingSlots($test, string $from, int $days = 1, ?string $staffId = null, ?object $service = null): array
{
    return bookingIn($test->ws, fn () => array_map(
        fn (array $s) => $s['start']->setTimezone('America/Bogota')->format('D H:i'),
        app(SlotFinder::class)->slots($test->ws, $service ?? $test->service, CarbonImmutable::parse($from, 'America/Bogota'), $days, $staffId, $test->now),
    ));
}

it('arma los horarios en la hora del negocio y los devuelve en UTC', function () {
    bookingHours($this->ws);
    $slots = bookingIn($this->ws, fn () => app(SlotFinder::class)->slots($this->ws, $this->service, CarbonImmutable::parse('2026-11-30', 'America/Bogota'), 1, null, $this->now));

    // 9:00 a 12:00 con citas de 60 min cada 15 min: 9:00 … 11:00 (9 horarios).
    expect($slots)->toHaveCount(9)
        ->and($slots[0]['start']->toIso8601ZuluString())->toBe('2026-11-30T14:00:00Z')
        ->and($slots[8]['start']->setTimezone('America/Bogota')->format('H:i'))->toBe('11:00')
        ->and($slots[0]['staff_id'])->toBeNull();
});

it('respeta la anticipación mínima y el máximo de días', function () {
    bookingHours($this->ws);
    $this->now = CarbonImmutable::parse('2026-11-30 08:30', 'America/Bogota');
    // Con 2 h de anticipación el primero es a las 10:30.
    expect(bookingSlots($this, '2026-11-30')[0])->toBe('Mon 10:30');

    $short = bookingService($this->ws, ['slug' => 'corta', 'max_days_ahead' => 3]);
    expect(bookingSlots($this, '2026-12-03', 1, null, $short))->not->toBeEmpty()
        ->and(bookingSlots($this, '2026-12-04', 1, null, $short))->toBeEmpty();
});

it('cierra los festivos del país y aplica las excepciones del horario', function () {
    bookingHours($this->ws, null, [1, 2, 3, 4, 5], '09:00', '12:00', [
        ['date' => '2026-12-02', 'closed' => true],
        ['date' => '2026-12-03', 'start' => '14:00', 'end' => '15:00'],
    ]);
    expect(bookingSlots($this, '2026-12-08'))->toBeEmpty()     // Inmaculada Concepción (CO)
        ->and(bookingSlots($this, '2026-12-02'))->toBeEmpty()  // cerrado ese día
        ->and(bookingSlots($this, '2026-12-03'))->toBe(['Thu 14:00'])
        ->and(bookingSlots($this, '2026-12-06'))->toBeEmpty(); // domingo sin horario
});

it('una cita ocupa su horario más el margen del servicio', function () {
    bookingHours($this->ws);
    $service = bookingService($this->ws, ['slug' => 'con-margen', 'buffer_min' => 15]);
    $contact = bookingContact($this->ws);
    bookingIn($this->ws, fn () => Appointment::query()->create([
        'workspace_id' => $this->ws->id, 'contact_id' => $contact->id, 'service_id' => $service->id,
        'starts_at' => CarbonImmutable::parse('2026-11-30 10:00', 'America/Bogota')->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-30 11:00', 'America/Bogota')->utc(),
        'status' => 'booked', 'manage_token' => str_repeat('a', 40),
    ]));

    // Ocupado de 9:45 a 11:15 con el margen: solo quedan 9:00 (fin 10:00 + 15 > 10:00 ✗)… y nada después de 11:15 que quepa antes de 12:00.
    expect(bookingSlots($this, '2026-11-30', 1, null, $service))->toBe([]);

    // Cancelada, ya no ocupa.
    bookingIn($this->ws, fn () => Appointment::query()->update(['status' => 'cancelled']));
    expect(bookingSlots($this, '2026-11-30', 1, null, $service))->toHaveCount(9);
});

it('cada persona usa su horario propio (o el del negocio) y su Google Calendar', function () {
    config(['igr-bookings.google.client_id' => 'cid', 'igr-bookings.google.client_secret' => 'secret']);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'new', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [
            ['start' => '2026-11-30T15:00:00Z', 'end' => '2026-11-30T16:00:00Z'], // 10:00–11:00 Bogotá
        ]]]]),
    ]);
    bookingHours($this->ws); // negocio: 9 a 12
    $laura = bookingStaff($this->ws, ['calendar' => ['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => time() + 3600, 'calendar_id' => 'primary']]);
    $pedro = bookingStaff($this->ws, ['name' => 'Pedro']);
    bookingHours($this->ws, $pedro->id, [1], '14:00', '16:00');

    $laura_slots = bookingSlots($this, '2026-11-30', 1, $laura->id);
    expect($laura_slots)->toBe(['Mon 09:00', 'Mon 11:00'])                                   // ocupado en Google de 10 a 11
        ->and(bookingSlots($this, '2026-11-30', 1, $pedro->id))->toBe(['Mon 14:00', 'Mon 14:15', 'Mon 14:30', 'Mon 14:45', 'Mon 15:00']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'freeBusy') && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('si Google Calendar falla, no bloquea la agenda', function () {
    config(['igr-bookings.google.client_id' => 'cid', 'igr-bookings.google.client_secret' => 'secret']);
    Http::fake(['*' => Http::response('caído', 500)]);
    bookingHours($this->ws);
    $laura = bookingStaff($this->ws, ['calendar' => ['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => time() + 3600]]);

    expect(bookingSlots($this, '2026-11-30', 1, $laura->id))->toHaveCount(9);
});
