<?php

use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/BookingFixtures.php';

/*
 * Dominio propio para la página de reservas (M18 + M22): con un dominio verificado
 * de propósito `booking`, los enlaces de la agenda (confirmación, recordatorios,
 * gestión de la cita) y la propia página usan ese dominio; Caddy lo autoriza.
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
    bookingHours($this->ws);
});

function bookingDomain(string $accountId, ?string $workspaceId, string $host, string $status = 'verified', string $purpose = 'booking'): CustomDomain
{
    Cache::flush();

    return CustomDomain::query()->create([
        'account_id' => $accountId, 'workspace_id' => $workspaceId, 'host' => $host, 'purpose' => $purpose, 'status' => $status,
        'verification_token' => Str::random(32), 'verified_at' => $status === 'verified' ? now() : null,
    ]);
}

it('arma los enlaces de la agenda con el dominio de reservas verificado del espacio de trabajo', function () {
    $pk = $this->site->public_key;
    $hub = rtrim((string) config('app.url'), '/');
    expect(BookingSettings::pageUrl($this->ws, 'valoracion'))->toBe("{$hub}/p/{$pk}/reservar/valoracion");

    bookingDomain((string) $this->ws->account_id, null, 'citas.agencia.co');
    bookingDomain((string) $this->ws->account_id, $this->ws->id, 'pendiente.clinica.co', 'pending');
    bookingDomain((string) $this->ws->account_id, $this->ws->id, 'panel.clinica.co', 'verified', 'panel');
    expect(BookingSettings::pageUrl($this->ws))->toBe("https://citas.agencia.co/p/{$pk}/reservar");

    bookingDomain((string) $this->ws->account_id, $this->ws->id, 'reservas.clinica.co');
    expect(BookingSettings::pageUrl($this->ws, 'valoracion'))->toBe("https://reservas.clinica.co/p/{$pk}/reservar/valoracion")
        ->and(BookingSettings::manageUrl($this->ws, 'abc'))->toBe("https://reservas.clinica.co/p/{$pk}/cita/abc");

    $other = $this->workspaceFor($this->userWith2fa());
    expect(BookingSettings::origin($other))->toBe($hub);
});

it('la página servida por el dominio propio mantiene ahí sus enlaces y Caddy lo autoriza', function () {
    $pk = $this->site->public_key;
    bookingDomain((string) $this->ws->account_id, $this->ws->id, 'reservas.clinica.co');

    $this->get("http://reservas.clinica.co/p/{$pk}/reservar")->assertOk()
        ->assertSee("https://reservas.clinica.co/p/{$pk}/reservar/valoracion", false);
    $res = $this->post("http://reservas.clinica.co/p/{$pk}/reservar/valoracion", ['start' => '2026-11-30T15:00:00Z', 'name' => 'Ana Ruiz', 'phone' => '300 123 4567']);
    $res->assertStatus(303);
    expect((string) $res->headers->get('Location'))->toStartWith("https://reservas.clinica.co/p/{$pk}/cita/");

    $this->get('/internal/caddy/ask?domain=reservas.clinica.co')->assertOk();
    $this->get('/internal/caddy/ask?domain=otra.clinica.co')->assertNotFound();
});
