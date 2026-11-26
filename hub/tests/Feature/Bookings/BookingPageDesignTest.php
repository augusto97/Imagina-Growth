<?php

use App\Domain\Bookings\Http\Public\BookingPageController;
use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/BookingFixtures.php';

/*
 * Rediseño de la página pública de reservas (0.3): color propio de la agenda, pasos, horarios por momento del día y
 * la confirmación con «Agregar a Google Calendar», el .ics y el WhatsApp del negocio. Sigue funcionando sin JavaScript.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([SyncAppointmentCalendar::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    [$this->site] = $this->connectedSite($this->ws);
    FakeMessageDispatcher::install();
    bookingFakeLeads();
    bookingService($this->ws);
    bookingHours($this->ws, null, [1, 2, 3, 4, 5], '09:00', '20:00');
    $this->base = '/p/'.$this->site->public_key;
});

it('pinta el color propio de la agenda; sin él, el azul de siempre; y el panel no guarda un color inválido', function () {
    // Sin color propio: el de la marca (Imagina Growth por defecto).
    $html = strtolower((string) $this->get("{$this->base}/reservar/valoracion")->assertOk()->getContent());
    expect($html)->toContain('--accent: #2563eb')->toContain('--accent-fg: #ffffff');

    $api = "/api/w/{$this->ws->publicId()}/bookings/settings";
    $this->actingAs($this->owner)->putJson($api, ['color' => 'rojo'])->assertUnprocessable()->assertJsonValidationErrors('color');
    $this->actingAs($this->owner)->putJson($api, ['color' => '#FDE047'])->assertOk()->assertJsonPath('settings.color', '#FDE047');

    // Un amarillo claro lleva el texto oscuro para que se lea.
    $this->get("{$this->base}/reservar/valoracion")->assertOk()->assertSee('--accent: #FDE047', false)->assertSee('--accent-fg: #0f172a', false);
});

it('muestra los pasos y agrupa los horarios en mañana, tarde y noche, cada uno con su fecha completa', function () {
    $html = $this->get("{$this->base}/reservar/valoracion")->assertOk()
        ->assertSee('Día y hora')->assertSee('aria-current="step"', false)
        ->assertSee('Mañana')->assertSee('Tarde')->assertSee('Noche')
        ->assertSee('Noviembre de 2026')
        ->assertSee('data-when="Lunes 30 de noviembre · 9:00 am"', false)
        ->getContent();
    // Sin JavaScript todo sigue siendo enlaces y radios dentro del formulario.
    expect($html)->toContain('name="start"')->toContain('form="booking-form"')->toContain('#horarios');
    expect(BookingPageController::period(11))->toBe('morning')->and(BookingPageController::period(12))->toBe('afternoon')
        ->and(BookingPageController::period(17))->toBe('afternoon')->and(BookingPageController::period(18))->toBe('evening');
});

it('la confirmación trae Google Calendar, el .ics y el WhatsApp del negocio con la cita escrita', function () {
    $this->ws->forceFill(['settings' => ['business_profile' => ['name' => 'Clínica Sonrisa', 'whatsapp' => '300 765 4321']]])->save();
    $this->post("{$this->base}/reservar/valoracion", ['start' => '2026-11-30T15:00:00Z', 'name' => 'Ana María Ruiz', 'phone' => '300 123 4567'])->assertStatus(303);
    $a = bookingIn($this->ws, fn () => Appointment::query()->firstOrFail());
    $html = $this->get("{$this->base}/cita/{$a->manage_token}?ok=nueva")->assertOk()
        ->assertSee('Tu cita quedó agendada')->assertSee('10:00 am – 11:00 am')
        ->assertSee('Agrégala a tu calendario')->assertSee('/calendario.ics', false)
        ->getContent();
    expect($html)->toContain('https://calendar.google.com/calendar/render?action=TEMPLATE')
        ->toContain('dates=20261130T150000Z%2F20261130T160000Z')
        ->toContain('https://wa.me/573007654321?text=')
        ->not->toContain('Ana María Ruiz');
});

it('sin WhatsApp del negocio no ofrece el botón, y una cita cancelada ofrece agendar otra', function () {
    $this->post("{$this->base}/reservar/valoracion", ['start' => '2026-11-30T15:00:00Z', 'name' => 'Ana María Ruiz', 'phone' => '300 123 4567'])->assertStatus(303);
    $a = bookingIn($this->ws, fn () => Appointment::query()->firstOrFail());
    $url = "{$this->base}/cita/{$a->manage_token}";
    expect($this->get($url)->assertOk()->getContent())->not->toContain('wa.me');
    $this->post("{$url}/cancelar")->assertStatus(303);
    $this->get("{$url}?ok=cancelada")->assertOk()->assertSee('Tu cita quedó cancelada')->assertSee('Agendar otra cita')
        ->assertDontSee('Agrégala a tu calendario');
    expect(BookingSettings::for($this->ws->refresh())['color'])->toBeNull();
});
