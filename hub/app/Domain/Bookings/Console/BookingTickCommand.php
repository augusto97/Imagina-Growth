<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Console;

use App\Domain\Bookings\Actions\AppointmentNotifier;
use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Cada 5 minutos (M18): recordatorios 24 h y 2 h antes de la cita (una vez cada uno
 * por hora de la cita) y la no asistencia automática 30 minutos después del final de
 * las citas que nadie marcó como cumplidas (si el negocio la tiene activa).
 */
final class BookingTickCommand extends Command
{
    protected $signature = 'igr:bookings:tick {--now= : Fecha y hora de referencia (pruebas)}';

    protected $description = 'Envía los recordatorios de las citas y marca las no asistencias';

    public function handle(CurrentWorkspace $current, AppointmentNotifier $notifier, ChangeAppointment $change): int
    {
        $now = ($this->option('now') !== null ? CarbonImmutable::parse((string) $this->option('now')) : CarbonImmutable::now())->utc();
        $sent = 0;
        $noShows = 0;
        $workspaceIds = Appointment::withoutWorkspaceScope()->whereIn('status', Appointment::ACTIVE)
            ->where('starts_at', '<=', $now->addHours(25))->where('ends_at', '>=', $now->subDays(2))
            ->distinct()->pluck('workspace_id');
        foreach ($workspaceIds as $workspaceId) {
            $workspace = Workspace::query()->find((string) $workspaceId);
            if ($workspace === null) {
                continue;
            }
            $current->run($workspace, function () use ($workspace, $now, $notifier, $change, &$sent, &$noShows) {
                $settings = BookingSettings::for($workspace);
                foreach ((array) $settings['reminder_hours'] as $hours) {
                    $hours = (int) $hours;
                    $kind = $hours >= 12 ? 'reminder_24' : 'reminder_2';
                    $due = Appointment::query()->whereIn('status', Appointment::ACTIVE)->where('starts_at', '>', $now)
                        ->where('starts_at', '<=', $now->addHours($hours))->where('created_at', '<=', $now->subMinutes(30))->get();
                    foreach ($due as $appointment) {
                        $reminders = (array) $appointment->reminders;
                        // El de 2 h no sale si la cita se agendó hace menos de 30 min (ya llegó la confirmación).
                        if (isset($reminders[$kind]) || ($kind === 'reminder_24' && CarbonImmutable::instance($appointment->starts_at)->lessThanOrEqualTo($now->addHours(3)))) {
                            continue;
                        }
                        $notifier->send($workspace, $appointment, $kind);
                        $reminders[$kind] = $now->toIso8601String();
                        $appointment->forceFill(['reminders' => $reminders])->save();
                        $sent++;
                    }
                }
                if ((bool) $settings['auto_no_show']) {
                    $missed = Appointment::query()->whereIn('status', Appointment::ACTIVE)
                        ->where('ends_at', '<=', $now->subMinutes((int) config('igr-bookings.no_show_after_min', 30)))->get();
                    foreach ($missed as $appointment) {
                        $change->mark($workspace, $appointment, 'no_show');
                        $noShows++;
                    }
                }
            });
        }
        $this->line(sprintf('%d recordatorios, %d no asistencias.', $sent, $noShows));

        return self::SUCCESS;
    }
}
