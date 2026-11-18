<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Jobs;

use App\Domain\Bookings\Google\GoogleCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Lleva la cita al Google Calendar de la persona: crea, mueve o borra el evento. */
final class SyncAppointmentCalendar implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public string $workspaceIdValue, public string $appointmentId)
    {
        $this->onQueue('default');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(GoogleCalendar $google): void
    {
        if (! GoogleCalendar::configured()) {
            return;
        }
        $appointment = Appointment::query()->find($this->appointmentId);
        $staff = $appointment?->staff_id !== null ? BookingStaff::query()->find($appointment->staff_id) : null;
        if ($appointment === null || $staff === null || ! $staff->calendarConnected()) {
            return;
        }
        if (! $appointment->isActive()) {
            if ($appointment->external_calendar_event_id !== null) {
                $google->delete($staff, $appointment->external_calendar_event_id);
                $appointment->forceFill(['external_calendar_event_id' => null])->save();
            }

            return;
        }
        $service = BookingService::query()->findOrFail($appointment->service_id);
        $appointment->forceFill(['external_calendar_event_id' => $google->upsert($staff, $appointment, $service)])->save();
    }
}
