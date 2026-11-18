<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Actions;

use App\Domain\Bookings\Jobs\SyncAppointmentCalendar;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\AppointmentEvents;
use App\Domain\Bookings\Support\SlotFinder;
use App\Domain\Bookings\Support\SlotTaken;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agenda una cita sin doble reserva (M18): candado por persona en Redis y, dentro
 * de la transacción, se vuelve a revisar que el horario siga libre y dentro de la
 * disponibilidad. Después crea el lead (`LeadIntake`, origen booking), emite
 * `appointment.booked`, sincroniza Google Calendar en cola y manda la confirmación.
 */
final class BookAppointment
{
    public function __construct(
        private readonly SlotFinder $slots,
        private readonly LeadIntake $leads,
        private readonly AppointmentNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $source  canal o página de origen (para el lead)
     *
     * @throws SlotTaken
     */
    public function handle(Workspace $workspace, BookingService $service, CarbonImmutable $start, ?string $staffId, string $contactId,
        string $channel = 'page', ?string $notes = null, array $source = [], ?string $siteId = null): Appointment
    {
        $start = $start->utc()->startOfMinute();
        $lock = Cache::lock('igr:booking:'.$workspace->id.':'.$service->id.':'.($staffId ?? 'any'), 10);
        $all = $staffId === null ? Cache::lock('igr:booking:'.$workspace->id.':any', 10) : null;
        $appointment = $lock->block(5, function () use ($all, $workspace, $service, $start, $staffId, $contactId, $channel, $notes) {
            $create = fn () => DB::transaction(function () use ($workspace, $service, $start, $staffId, $contactId, $channel, $notes) {
                $free = $this->slots->freeAt($workspace, $service, $start, $staffId);
                if ($free === false) {
                    throw new SlotTaken(__('Ese horario ya no está disponible. Elige otro.'));
                }
                // Revisión final contra las citas que se acaban de crear en otra petición.
                $overlap = Appointment::query()->whereIn('status', Appointment::ACTIVE)
                    ->where('starts_at', '<', $start->addMinutes($service->duration_min + $service->buffer_min))
                    ->where('ends_at', '>', $start->subMinutes($service->buffer_min))
                    ->when($free === null, fn ($q) => $q->whereNull('staff_id'), fn ($q) => $q->where('staff_id', $free))
                    ->lockForUpdate()->exists();
                if ($overlap) {
                    throw new SlotTaken(__('Ese horario ya no está disponible. Elige otro.'));
                }

                return Appointment::query()->create([
                    'workspace_id' => $workspace->id, 'contact_id' => $contactId, 'service_id' => $service->id, 'staff_id' => $free,
                    'starts_at' => $start, 'ends_at' => $start->addMinutes($service->duration_min), 'status' => 'booked',
                    'channel' => $channel, 'manage_token' => Str::random(40), 'notes' => $notes, 'reminders' => [],
                ]);
            });

            return $all !== null ? $all->block(5, $create) : $create();
        });

        $leadId = $this->leads->capture(new LeadIntakeInput(
            workspaceId: $workspace->id, contactId: $contactId, origin: 'booking', originRef: $appointment->publicId(),
            service: $service->name, value: $service->price !== null ? (string) $service->price : null, source: $source, siteId: $siteId,
        ));
        // El contrato no fija si el ID viene con prefijo (ld_…): la columna guarda el ULID.
        $leadUlid = $leadId !== null ? (string) preg_replace('/^[a-z]+_/', '', $leadId) : null;
        if ($leadUlid !== null && strlen($leadUlid) === 26) {
            $appointment->forceFill(['lead_id' => $leadUlid])->save();
        }
        AppointmentEvents::booked($workspace, $appointment, $service);
        SyncAppointmentCalendar::dispatch($workspace->id, $appointment->id)->afterCommit();
        $this->notifier->send($workspace, $appointment, 'confirmation');

        return $appointment;
    }
}
