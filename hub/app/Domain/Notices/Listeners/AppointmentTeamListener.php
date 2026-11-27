<?php

declare(strict_types=1);

namespace App\Domain\Notices\Listeners;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Notices\Jobs\NotifyAppointmentTeam;
use App\Ingest\Events\EventIngested;
use App\Support\Ids\PrefixedId;
use Illuminate\Support\Facades\Auth;

/**
 * Cada `appointment.booked|rescheduled|cancelled` (y `confirmed` cuando confirma el cliente) avisa al equipo
 * (lote 0.4.0). Escucha los eventos del hub para no tocar las acciones de la agenda; aquí solo se filtra y se encola
 * (corre por cada evento de la ingesta: tiene que ser barato). Quien hizo el cambio en el panel no se avisa a sí mismo.
 */
final class AppointmentTeamListener
{
    private const EVENTS = [
        'appointment.booked' => 'booked',
        'appointment.rescheduled' => 'rescheduled',
        'appointment.cancelled' => 'cancelled',
        'appointment.confirmed' => 'confirmed',
    ];

    public function handle(EventIngested $event): void
    {
        $what = self::EVENTS[$event->event] ?? null;
        if ($what === null) {
            return;
        }
        $props = (array) ($event->envelope['properties'] ?? []);
        $by = is_string($props['by'] ?? null) ? $props['by'] : 'system';
        if ($what === 'confirmed' && $by !== 'contact') {
            return;
        }
        $id = PrefixedId::decode(Appointment::ID_PREFIX, is_string($props['appointment_id'] ?? null) ? $props['appointment_id'] : null);
        if ($id === null) {
            return;
        }
        $actor = Auth::id();
        NotifyAppointmentTeam::dispatch($event->workspaceId, $id, $what, $by, is_string($actor) ? $actor : null)->afterCommit();
    }
}
