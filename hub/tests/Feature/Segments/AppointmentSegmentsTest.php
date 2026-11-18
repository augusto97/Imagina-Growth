<?php

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Segments\Support\DependencyIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

require_once dirname(__DIR__).'/Bookings/BookingFixtures.php';

/*
 * Condiciones de citas en segmentos (M04 + M18, `type: service`): cita próxima,
 * alguna vez agendó, estado, servicio, canal y fecha; los eventos appointment.*
 * recalculan en tiempo real los segmentos que las usan.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->base = "/api/w/{$this->ws->publicId()}/segments";
});

function apptSegRow($test, string $email, array $attrs): void
{
    $contact = bookingContact($test->ws, ['email' => $email, 'phone' => null]);
    $service = $attrs['service'];
    unset($attrs['service']);
    $start = $attrs['starts_at'];
    app(CurrentWorkspace::class)->run($test->ws, fn () => Appointment::query()->create($attrs + [
        'workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'service_id' => $service->id,
        'ends_at' => $start->addHour(), 'status' => 'booked', 'channel' => 'page',
    ]));
}

function apptSegCount($test, array $condition): int
{
    return (int) $test->actingAs($test->owner)->postJson($test->base.'/preview', ['definition' => ['op' => 'and', 'conditions' => [['type' => 'service'] + $condition]]])
        ->assertOk()->json('count');
}

it('segmenta por citas: próxima, alguna vez, estado, servicio, canal y fecha', function () {
    $valoracion = bookingService($this->ws);
    $limpieza = bookingService($this->ws, ['name' => 'Limpieza dental', 'slug' => 'limpieza']);
    $now = CarbonImmutable::now();
    apptSegRow($this, 'proxima@example.com', ['service' => $valoracion, 'starts_at' => $now->addDays(2), 'status' => 'confirmed']);
    apptSegRow($this, 'cumplio@example.com', ['service' => $limpieza, 'starts_at' => $now->subDays(5), 'status' => 'completed', 'channel' => 'inbox']);
    apptSegRow($this, 'falto@example.com', ['service' => $valoracion, 'starts_at' => $now->subDays(40), 'status' => 'no_show']);
    bookingContact($this->ws, ['email' => 'nunca@example.com', 'phone' => null]);

    expect(apptSegCount($this, ['field' => 'has_upcoming', 'operator' => 'is_true']))->toBe(1)
        ->and(apptSegCount($this, ['field' => 'has_upcoming', 'operator' => 'is_false']))->toBe(3)
        ->and(apptSegCount($this, ['field' => 'has_appointment', 'operator' => 'is_true']))->toBe(3)
        ->and(apptSegCount($this, ['field' => 'has_appointment', 'operator' => 'is_false']))->toBe(1)
        ->and(apptSegCount($this, ['field' => 'appointment_status', 'operator' => 'in', 'value' => ['no_show']]))->toBe(1)
        ->and(apptSegCount($this, ['field' => 'service', 'operator' => 'contains', 'value' => 'Limpieza']))->toBe(1)
        ->and(apptSegCount($this, ['field' => 'booking_channel', 'operator' => 'eq', 'value' => 'inbox']))->toBe(1)
        ->and(apptSegCount($this, ['field' => 'starts_at', 'operator' => 'within_last_days', 'value' => 30]))->toBe(1);
});

it('valida los campos de citas y ofrece sus opciones', function () {
    $this->actingAs($this->owner)->postJson($this->base.'/preview', ['definition' => ['op' => 'and', 'conditions' => [['type' => 'service', 'field' => 'appointment_status', 'operator' => 'in', 'value' => ['inventado']]]]])
        ->assertUnprocessable();
    $this->actingAs($this->owner)->postJson($this->base.'/preview', ['definition' => ['op' => 'and', 'conditions' => [['type' => 'service', 'field' => 'has_upcoming', 'operator' => 'gte', 'value' => 1]]]])
        ->assertUnprocessable();
    $this->actingAs($this->owner)->getJson($this->base.'/options')->assertOk()->assertJsonPath('service_fields.0.key', 'has_upcoming');
});

it('los eventos appointment.* recalculan en tiempo real los segmentos con citas', function () {
    expect(DependencyIndex::isRelevant([['id' => 'x', 'events' => [], 'kinds' => ['service']]], 'appointment.booked'))->toBeTrue()
        ->and(DependencyIndex::isRelevant([['id' => 'y', 'events' => [], 'kinds' => ['lead']]], 'appointment.no_show'))->toBeFalse();
});
