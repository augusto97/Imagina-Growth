<?php

use App\Domain\Bookings\Models\BookingAvailability;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Str;

/*
 * Ayudas de las pruebas de la agenda (M18). Todas llevan el prefijo `booking`
 * (las pruebas de Pest comparten el espacio de nombres global).
 */

/** @param array<string, mixed> $attrs */
function bookingService(Workspace $ws, array $attrs = []): BookingService
{
    return app(CurrentWorkspace::class)->run($ws, fn () => BookingService::query()->create($attrs + [
        'workspace_id' => $ws->id, 'name' => 'Valoración inicial', 'slug' => 'valoracion', 'duration_min' => 60, 'buffer_min' => 0,
        'price' => '80000', 'location' => 'onsite', 'min_notice_hours' => 2, 'max_days_ahead' => 60, 'active' => true,
    ]));
}

/** @param array<string, mixed> $attrs */
function bookingStaff(Workspace $ws, array $attrs = []): BookingStaff
{
    return app(CurrentWorkspace::class)->run($ws, fn () => BookingStaff::query()->create($attrs + [
        'workspace_id' => $ws->id, 'name' => 'Laura Gómez', 'timezone' => 'America/Bogota', 'active' => true,
    ]));
}

/**
 * Horario de lunes a viernes (o los días dados) del negocio o de una persona.
 *
 * @param  list<int>  $weekdays
 * @param  list<array<string, mixed>>  $exceptions
 */
function bookingHours(Workspace $ws, ?string $staffId = null, array $weekdays = [1, 2, 3, 4, 5], string $start = '09:00', string $end = '12:00', array $exceptions = []): void
{
    app(CurrentWorkspace::class)->run($ws, function () use ($ws, $staffId, $weekdays, $start, $end, $exceptions) {
        foreach ($weekdays as $i => $day) {
            BookingAvailability::query()->create(['workspace_id' => $ws->id, 'staff_id' => $staffId, 'weekday' => $day, 'start_time' => $start,
                'end_time' => $end, 'exceptions' => $i === 0 ? $exceptions : null]);
        }
    });
}

function bookingContact(Workspace $ws, array $attrs = []): Contact
{
    return app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create($attrs + [
        'workspace_id' => $ws->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com', 'phone' => '+573001234567',
    ]));
}

/** LeadIntake de prueba: guarda lo que recibe y devuelve un ld_… */
function bookingFakeLeads(): object
{
    $fake = new class implements LeadIntake
    {
        /** @var list<LeadIntakeInput> */
        public array $captured = [];

        public function capture(LeadIntakeInput $input): ?string
        {
            $this->captured[] = $input;

            return 'ld_'.strtoupper((string) Str::ulid());
        }
    };
    app()->instance(LeadIntake::class, $fake);

    return $fake;
}

function bookingIn(Workspace $ws, Closure $fn): mixed
{
    return app(CurrentWorkspace::class)->run($ws, $fn);
}
