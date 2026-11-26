<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Actions;

use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\AppointmentEvents;
use App\Domain\Bookings\Support\SlotFinder;
use App\Domain\Bookings\Support\SlotTaken;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cambios de una cita (M18): reprogramar (con las mismas reglas que agendar),
 * cancelar y marcar cumplida o no asistida. Cada cambio emite su evento, mueve
 * Google Calendar en cola y avisa a la persona cuando corresponde.
 */
final class ChangeAppointment
{
    public function __construct(private readonly SlotFinder $slots, private readonly AppointmentNotifier $notifier) {}

    /**
     * @param  'contact'|'staff'|'system'  $by
     * @param  bool  $confirmedInChat  el cambio se confirmó en el chat: el aviso sale solo por correo
     */
    public function reschedule(Workspace $workspace, Appointment $appointment, CarbonImmutable $start, string $by, bool $confirmedInChat = false): Appointment
    {
        if (! $appointment->isActive()) {
            throw new InvalidArgumentException(__('Esta cita ya no se puede cambiar.'));
        }
        $service = BookingService::query()->findOrFail($appointment->service_id);
        $start = $start->utc()->startOfMinute();
        $from = CarbonImmutable::instance($appointment->starts_at);
        $lock = Cache::lock('igr:booking:'.$workspace->id.':'.$service->id.':'.($appointment->staff_id ?? 'any'), 10);
        $lock->block(5, function () use ($workspace, $service, $appointment, $start) {
            DB::transaction(function () use ($workspace, $service, $appointment, $start) {
                // Mientras se revisa, la cita no ocupa su propio horario.
                $free = $this->slots->ignoring($appointment)->freeAt($workspace, $service, $start, $appointment->staff_id);
                if ($free === false) {
                    throw new SlotTaken(__('Ese horario ya no está disponible. Elige otro.'));
                }
                $appointment->forceFill([
                    'starts_at' => $start, 'ends_at' => $start->addMinutes($service->duration_min), 'status' => 'rescheduled',
                    'staff_id' => $free, 'reminders' => [],
                ])->save();
            });
        });
        AppointmentEvents::rescheduled($workspace, $appointment, $from, $by);
        SyncAppointmentCalendar::dispatch($workspace->id, $appointment->id)->afterCommit();
        $this->notifier->send($workspace, $appointment, 'rescheduled', whatsapp: ! $confirmedInChat);

        return $appointment;
    }

    /** @param 'contact'|'staff'|'system' $by */
    public function cancel(Workspace $workspace, Appointment $appointment, string $by, ?string $reason = null, bool $confirmedInChat = false): Appointment
    {
        if ($appointment->status === 'cancelled') {
            return $appointment;
        }
        if (! $appointment->isActive()) {
            throw new InvalidArgumentException(__('Esta cita ya no se puede cancelar.'));
        }
        $appointment->forceFill(['status' => 'cancelled'])->save();
        AppointmentEvents::cancelled($workspace, $appointment, $by, $reason !== null ? mb_substr($reason, 0, 120) : null);
        SyncAppointmentCalendar::dispatch($workspace->id, $appointment->id)->afterCommit();
        $this->notifier->send($workspace, $appointment, 'cancelled', whatsapp: ! $confirmedInChat);

        return $appointment;
    }

    /**
     * @param  'confirmed'|'completed'|'no_show'  $status
     * @param  'contact'|'staff'|'system'  $by  quién confirmó (`appointment.confirmed`, v8)
     */
    public function mark(Workspace $workspace, Appointment $appointment, string $status, string $by = 'staff'): Appointment
    {
        if ($appointment->status === $status) {
            return $appointment;
        }
        if ($status === 'confirmed' && ! $appointment->isActive()) {
            throw new InvalidArgumentException(__('Esta cita ya no se puede confirmar.'));
        }
        if ($status !== 'confirmed' && $appointment->status === 'cancelled') {
            throw new InvalidArgumentException(__('Una cita cancelada no se puede marcar como cumplida ni como no asistida.'));
        }
        $appointment->forceFill(['status' => $status])->save();
        if ($status === 'confirmed') {
            AppointmentEvents::confirmed($workspace, $appointment, $by);
        } else {
            AppointmentEvents::status($workspace, $appointment, $status);
        }

        return $appointment;
    }
}
