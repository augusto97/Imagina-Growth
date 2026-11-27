<?php

declare(strict_types=1);

namespace App\Domain\Notices\Jobs;

use App\Domain\Bookings\Http\Public\PublicBooking;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Notices\Actions\NotifyTeam;
use App\Domain\Notices\Data\TeamNotice;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Avisa al equipo de una cita (lote 0.4.0, docs/avisos.md §2): nueva, movida, cancelada o confirmada por el cliente.
 * A la persona que atiende (si usa el panel; si no, a su correo) y a dueños y administradores según «Mis avisos».
 * Nunca a quien hizo el cambio en el panel. Solo IDs en la cola: el nombre del contacto se lee al correr.
 */
final class NotifyAppointmentTeam implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const EVENTS = ['booked', 'rescheduled', 'cancelled', 'confirmed'];

    public int $tries = 3;

    public int $timeout = 120;

    /** @param 'booked'|'rescheduled'|'cancelled'|'confirmed' $what */
    public function __construct(
        public string $workspaceIdValue,
        public string $appointmentId,
        public string $what,
        public string $by = 'system',
        public ?string $actorUserId = null,
    ) {
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

    public function handle(NotifyTeam $notify): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        $appointment = Appointment::query()->find($this->appointmentId);
        if ($workspace === null || $appointment === null || ! in_array($this->what, self::EVENTS, true)) {
            return;
        }
        $notify->handle($workspace, self::notice($workspace, $appointment, $this->what, $this->by, $this->actorUserId));
    }

    public static function notice(Workspace $workspace, Appointment $appointment, string $what, string $by, ?string $actorUserId): TeamNotice
    {
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        $start = CarbonImmutable::instance($appointment->starts_at);
        $service = BookingService::query()->find($appointment->service_id);
        $staff = $appointment->staff_id !== null ? BookingStaff::query()->find($appointment->staff_id) : null;
        $contact = Contact::query()->find($appointment->contact_id);
        $name = $contact !== null && $contact->displayName() !== '' ? $contact->displayName() : __('Un cliente');
        $vars = [
            'name' => $name, 'service' => $service->name ?? __('Cita'), 'day' => PublicBooking::day($start, $tz), 'time' => PublicBooking::time($start, $tz),
            'staff' => $staff !== null ? __(' con :name', ['name' => $staff->name]) : '',
            'via' => match ($appointment->channel) {
                'page' => __('en la página de reservas'),
                'agent', 'inbox' => __('por el chat'),
                'manual' => __('desde el panel'),
                default => __('por la API'),
            },
        ];
        $fromCustomer = $by === 'contact';
        [$title, $body] = match ($what) {
            'booked' => [__('Nueva cita: :service', $vars), __(':name agendó para el :day a las :time:staff (:via).', $vars)],
            'rescheduled' => [__('Cita movida: :service', $vars), $fromCustomer
                ? __(':name la pasó al :day a las :time:staff.', $vars)
                : __('La cita de :name quedó el :day a las :time:staff.', $vars)],
            'cancelled' => [__('Cita cancelada: :service', $vars), $fromCustomer
                ? __(':name canceló su cita del :day a las :time.', $vars)
                : __('Se canceló la cita de :name del :day a las :time.', $vars)],
            default => [__(':name confirmó su cita', $vars), __(':service el :day a las :time:staff.', $vars)],
        };
        $staffEmail = $staff !== null && $staff->user_id === null && is_string($staff->email) && $staff->email !== '' ? [['name' => $staff->name, 'email' => $staff->email]] : [];

        return new TeamNotice(
            kind: 'appointment.'.$what,
            group: 'appointments',
            title: $title,
            body: $body,
            path: 'agenda?fecha='.$start->setTimezone($tz)->format('Y-m-d').'&cita='.$appointment->publicId(),
            dedupe: 'appointment:'.$appointment->id.':'.$what.':'.$start->getTimestamp(),
            subjectType: 'appointment',
            subjectId: $appointment->id,
            logEvent: $what,
            userIds: $staff?->user_id !== null ? [(string) $staff->user_id] : [],
            emails: $staffEmail,
            excludeUserId: $actorUserId,
            contactId: $appointment->contact_id,
        );
    }
}
