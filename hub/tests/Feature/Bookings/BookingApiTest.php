<?php

use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingAvailability;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/BookingFixtures.php';

/*
 * La agenda en el panel (M18): ver y mover citas con `useLeads`; configurar
 * servicios, personas, horarios y ajustes con `manageServices`; auditoría.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([SyncAppointmentCalendar::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->ws->forceFill(['timezone' => 'America/Bogota', 'country' => 'CO'])->save();
    $this->connectedSite($this->ws);
    FakeMessageDispatcher::install();
    bookingFakeLeads();
    $this->base = "/api/w/{$this->ws->publicId()}/bookings";
});

it('quien administra arma servicios, personas y horarios; marketing solo agenda', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);

    $staff = $this->actingAs($this->owner)->postJson("{$this->base}/staff", ['name' => 'Laura Gómez', 'email' => 'laura.gomez.consultorio@example.com'])
        ->assertCreated()->assertJsonPath('timezone', 'America/Bogota')->assertJsonPath('calendar_connected', false)->json();
    $service = $this->actingAs($this->owner)->postJson("{$this->base}/services", [
        'name' => 'Limpieza dental', 'duration_min' => 45, 'buffer_min' => 15, 'price' => 120000, 'location' => 'onsite', 'staff_ids' => [$staff['id']],
    ])->assertCreated()->assertJsonPath('slug', 'limpieza-dental')->assertJsonPath('staff_ids', [$staff['id']])->json();
    $this->actingAs($this->owner)->postJson("{$this->base}/services", ['name' => 'Limpieza dental'])->assertCreated()->assertJsonPath('slug', 'limpieza-dental-2');

    $this->actingAs($this->owner)->putJson("{$this->base}/availability", ['staff_id' => $staff['id'], 'rows' => [
        ['weekday' => 1, 'start' => '08:00', 'end' => '12:00'], ['weekday' => 1, 'start' => '14:00', 'end' => '18:00'],
    ], 'exceptions' => [['date' => '2026-12-24', 'closed' => true]]])->assertOk()->assertJsonCount(2, 'availability');
    $this->actingAs($this->owner)->putJson("{$this->base}/availability", ['rows' => [['weekday' => 2, 'start' => '10:00', 'end' => '09:00']]])->assertUnprocessable();

    $this->actingAs($marketing)->postJson("{$this->base}/services", ['name' => 'X'])->assertForbidden();
    $this->actingAs($marketing)->putJson("{$this->base}/settings", ['enabled' => false])->assertForbidden();
    $this->actingAs($marketing)->getJson($this->base)->assertOk()->assertJsonPath('can_manage', false)->assertJsonCount(2, 'services');
    $this->actingAs($viewer)->getJson($this->base)->assertForbidden();

    $slots = $this->actingAs($marketing)->getJson("{$this->base}/slots?service={$service['id']}&date=2026-11-30&days=1")->assertOk()->json('data');
    expect($slots[0]['start'])->toBe('2026-11-30T14:00:00Z')->and($slots[0]['staff_id'])->toBe($staff['id']); // 7:00 + 2 h de anticipación → 9:00

    expect(DB::table('audit_logs')->where('workspace_id', $this->ws->id)->pluck('action')->all())
        ->toContain('booking_staff.created', 'booking_service.created', 'bookings.availability_updated');
    expect((string) DB::table('booking_staff')->value('email'))->not->toContain('laura');
});

it('agenda desde el panel y cambia el estado de las citas', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $service = bookingService($this->ws);
    bookingHours($this->ws);
    $contact = bookingContact($this->ws);

    $id = $this->actingAs($marketing)->postJson("{$this->base}/appointments", [
        'contact_id' => $contact->publicId(), 'service_id' => $service->publicId(), 'start' => '2026-11-30T15:00:00Z', 'notes' => 'Primera vez',
    ])->assertCreated()->assertJsonPath('channel', 'manual')->assertJsonPath('contact.name', 'Ana Ruiz')
        ->assertJsonPath('contact.email', fn ($e) => $e !== 'ana@example.com')->json('id'); // marketing no exporta: enmascarado
    $this->actingAs($marketing)->postJson("{$this->base}/appointments", [
        'contact_id' => $contact->publicId(), 'service_id' => $service->publicId(), 'start' => '2026-11-30T15:00:00Z',
    ])->assertUnprocessable()->assertJsonValidationErrors('start');

    $list = $this->actingAs($this->owner)->getJson("{$this->base}/appointments?from=2026-11-30T00:00:00Z&to=2026-12-07T00:00:00Z")->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['contact']['email'])->toBe('ana@example.com')->and($list[0])->not->toHaveKey('notes');

    $this->actingAs($marketing)->postJson("{$this->base}/appointments/{$id}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');
    $this->actingAs($marketing)->postJson("{$this->base}/appointments/{$id}/reschedule", ['start' => '2026-12-01T16:00:00Z'])->assertOk()
        ->assertJsonPath('status', 'rescheduled')->assertJsonPath('starts_at', '2026-12-01T16:00:00Z');
    $this->actingAs($marketing)->postJson("{$this->base}/appointments/{$id}/complete")->assertOk()->assertJsonPath('status', 'completed');
    $this->actingAs($marketing)->postJson("{$this->base}/appointments/{$id}/cancel")->assertUnprocessable();
    $this->actingAs($marketing)->postJson("{$this->base}/appointments/{$id}/borrar")->assertNotFound();

    $metrics = $this->actingAs($marketing)->getJson("{$this->base}/metrics?days=30")->assertOk()->json();
    expect($metrics['booked'])->toBe(1)->and($metrics['completed'])->toBe(1)->and($metrics['no_show_rate'])->toBeNull()
        ->and($metrics['by_channel'][0])->toBe(['channel' => 'manual', 'count' => 1]);
});

it('guarda los ajustes de la página y da su enlace', function () {
    $res = $this->actingAs($this->owner)->putJson("{$this->base}/settings", ['page_title' => 'Agenda tu valoración', 'reminder_hours' => [48, 3], 'require_email' => true, 'otra' => 1])
        ->assertOk()->assertJsonPath('settings.page_title', 'Agenda tu valoración')->assertJsonPath('settings.reminder_hours', [48, 3]);
    expect($res->json('settings'))->not->toHaveKey('otra');
    $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonPath('page_url', fn ($u) => str_contains($u, '/p/pk_') && str_ends_with($u, '/reservar'));
});

it('un servicio con citas se desactiva en vez de borrarse', function () {
    $service = bookingService($this->ws);
    $empty = bookingService($this->ws, ['slug' => 'otro']);
    bookingHours($this->ws);
    $contact = bookingContact($this->ws);
    bookingIn($this->ws, fn () => app(BookAppointment::class)->handle($this->ws, $service, CarbonImmutable::parse('2026-11-30T15:00:00Z'), null, $contact->id));

    $this->actingAs($this->owner)->deleteJson("{$this->base}/services/{$service->publicId()}")->assertNoContent();
    $this->actingAs($this->owner)->deleteJson("{$this->base}/services/{$empty->publicId()}")->assertNoContent();
    bookingIn($this->ws, function () use ($service, $empty) {
        expect(BookingService::query()->find($service->id)->active)->toBeFalse()->and(BookingService::query()->find($empty->id))->toBeNull()
            ->and(Appointment::query()->count())->toBe(1)->and(BookingAvailability::query()->count())->toBe(5);
    });
});
