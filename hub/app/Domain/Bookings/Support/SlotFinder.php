<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Support;

use App\Domain\Bookings\Google\BusyTimes;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingAvailability;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Models\BookingStaff;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;

/**
 * Horarios libres de un servicio (M18). Cada persona usa su horario semanal o,
 * si no tiene, el del negocio, en su zona horaria. Se descuentan los festivos del
 * país (salvo una excepción con horario para ese día), las excepciones, las citas
 * que ocupan el horario con el margen del servicio, lo ocupado en Google Calendar,
 * la anticipación mínima y el máximo de días.
 */
final class SlotFinder
{
    private ?Appointment $ignore = null;

    public function __construct(private readonly BusyTimes $busy) {}

    /** Una copia que no cuenta como ocupado el horario de esa cita (para reprogramarla). */
    public function ignoring(?Appointment $appointment): self
    {
        $copy = clone $this;
        $copy->ignore = $appointment;

        return $copy;
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, staff_id: string|null}> en UTC, ordenados
     */
    public function slots(Workspace $workspace, BookingService $service, CarbonImmutable $fromDate, int $days, ?string $staffId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $earliest = $now->addHours($service->min_notice_hours);
        $latest = $now->addDays($service->max_days_ahead)->endOfDay();
        $staff = $this->staffFor($service, $staffId);
        $step = max(5, (int) config('igr-bookings.slot_step_min', 15));
        $business = BookingAvailability::query()->whereNull('staff_id')->get();
        $out = [];
        foreach ($staff as $person) {
            $tz = $person?->timezone ?: ($workspace->timezone ?: 'America/Bogota');
            $own = $person !== null ? BookingAvailability::query()->where('staff_id', $person->id)->get() : collect();
            $rows = $own->isNotEmpty() ? $own : $business;
            $rangeStart = CarbonImmutable::parse($fromDate->toDateString(), $tz)->startOfDay();
            $rangeEnd = $rangeStart->addDays(max(1, $days));
            $taken = $this->taken($person?->id, $rangeStart->utc(), $rangeEnd->utc(), $service->buffer_min);
            foreach ($person !== null ? $this->busy->forStaff($person, $rangeStart->utc(), $rangeEnd->utc()) : [] as $b) {
                if ($this->ignore !== null && $b[0]->equalTo($this->ignore->starts_at) && $b[1]->equalTo($this->ignore->ends_at)) {
                    continue; // el evento de Google de la misma cita
                }
                $taken[] = $b;
            }
            for ($d = 0; $d < max(1, $days); $d++) {
                $date = $rangeStart->addDays($d);
                foreach ($this->windows($rows, $date, (string) ($workspace->country ?: 'CO')) as [$start, $end]) {
                    for ($t = $start; $t->addMinutes($service->duration_min)->lessThanOrEqualTo($end); $t = $t->addMinutes($step)) {
                        $slotEnd = $t->addMinutes($service->duration_min);
                        if ($t->lessThan($earliest) || $t->greaterThan($latest) || self::overlaps($taken, $t, $slotEnd)) {
                            continue;
                        }
                        $out[] = ['start' => $t->utc(), 'end' => $slotEnd->utc(), 'staff_id' => $person?->id];
                    }
                }
            }
        }
        usort($out, fn (array $a, array $b) => [$a['start']->getTimestamp(), (string) $a['staff_id']] <=> [$b['start']->getTimestamp(), (string) $b['staff_id']]);

        return $out;
    }

    /** ¿Está libre ese inicio para esa persona (o para alguien, si no se dice)? Devuelve la persona o false. */
    public function freeAt(Workspace $workspace, BookingService $service, CarbonImmutable $start, ?string $staffId, ?CarbonImmutable $now = null): string|null|false
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        foreach ($this->slots($workspace, $service, $start->setTimezone($tz)->subDay(), 3, $staffId, $now) as $slot) {
            if ($slot['start']->equalTo($start)) {
                return $slot['staff_id'];
            }
        }

        return false;
    }

    /** @return list<BookingStaff|null> */
    private function staffFor(BookingService $service, ?string $staffId): array
    {
        $query = BookingStaff::query()->where('active', true)->orderBy('created_at');
        $ids = array_values(array_filter((array) $service->staff_ids));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        if ($staffId !== null) {
            $query->whereKey($staffId);
        }
        $staff = $query->get()->all();
        if ($staff === [] && $staffId === null && BookingStaff::query()->count() === 0) {
            return [null]; // negocio sin personas: un solo calendario con el horario del negocio
        }

        return array_values($staff);
    }

    /**
     * Franjas del día en la zona de la persona: las excepciones mandan; un festivo cierra.
     *
     * @param  iterable<BookingAvailability>  $rows
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windows(iterable $rows, CarbonImmutable $date, string $country): array
    {
        $day = $date->toDateString();
        $special = null;
        $weekly = [];
        foreach ($rows as $row) {
            foreach ((array) $row->exceptions as $ex) {
                if (($ex['date'] ?? null) !== $day) {
                    continue;
                }
                if (! empty($ex['closed'])) {
                    return [];
                }
                if (isset($ex['start'], $ex['end'])) {
                    $special[] = [self::at($date, (string) $ex['start']), self::at($date, (string) $ex['end'])];
                }
            }
            if ($row->weekday === $date->isoWeekday()) {
                $weekly[] = [self::at($date, (string) $row->start_time), self::at($date, (string) $row->end_time)];
            }
        }
        if ($special !== null) {
            return $special;
        }
        if (Holidays::isHoliday($country, $date)) {
            return [];
        }
        usort($weekly, fn ($a, $b) => $a[0] <=> $b[0]);

        return $weekly;
    }

    /**
     * Citas que ocupan el horario, con el margen del servicio alrededor.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function taken(?string $staffId, CarbonImmutable $from, CarbonImmutable $to, int $buffer): array
    {
        $query = Appointment::query()->whereIn('status', Appointment::ACTIVE)
            ->where('starts_at', '<', $to->addMinutes($buffer))->where('ends_at', '>', $from->subMinutes($buffer));
        $staffId === null ? $query->whereNull('staff_id') : $query->where('staff_id', $staffId);
        if ($this->ignore !== null) {
            $query->whereKeyNot($this->ignore->getKey());
        }

        return array_values($query->get(['starts_at', 'ends_at'])->map(fn (Appointment $a) => [
            CarbonImmutable::instance($a->starts_at)->subMinutes($buffer), CarbonImmutable::instance($a->ends_at)->addMinutes($buffer),
        ])->all());
    }

    /** @param list<array{0: CarbonImmutable, 1: CarbonImmutable}> $taken */
    private static function overlaps(array $taken, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($taken as [$a, $b]) {
            if ($start->lessThan($b) && $end->greaterThan($a)) {
                return true;
            }
        }

        return false;
    }

    private static function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return $date->setTime($h, $m);
    }
}
