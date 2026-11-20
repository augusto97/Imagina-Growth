<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Chat;

use App\Domain\Bookings\Http\Public\PublicBooking;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\SlotFinder;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Los horarios que el agente ofrece en el chat (M18, auditoría §5.1 función 4): salen de
 * `SlotFinder` con las reglas de la agenda (horario por persona, festivos, citas y su
 * margen, Google Calendar, anticipación mínima y máximo de días), en la zona horaria del
 * negocio, y se arman como botones (hasta 3) o como lista por día (hasta 10 filas), con
 * los límites de WhatsAppInteractive.
 *
 * Sin fecha: los próximos días, hasta 3 horarios por día y separados al menos una hora (o
 * lo que dura el servicio). Con fecha: hasta 10 de ese día. Con hora exacta: esa, si está
 * libre; si no, las más cercanas de ese día.
 */
final class ChatSlots
{
    public const PARTS = ['any', 'morning', 'afternoon', 'evening'];

    private const SEARCH_DAYS = 14;

    public function __construct(private readonly SlotFinder $finder) {}

    /**
     * @param  'any'|'morning'|'afternoon'|'evening'  $part
     * @return list<CarbonImmutable> inicios en UTC
     */
    public function find(Workspace $workspace, BookingService $service, ?string $date = null, string $part = 'any', ?string $time = null, ?Appointment $moving = null, int $max = WhatsAppInteractive::LIST_MAX_ROWS): array
    {
        $tz = self::timezone($workspace);
        $now = CarbonImmutable::now();
        $day = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, $tz)->startOfDay() : null;
        if ($day !== null && $day->lessThan($now->setTimezone($tz)->startOfDay())) {
            $day = null; // una fecha pasada: se buscan los próximos días
        }
        $from = $day ?? $now->setTimezone($tz)->startOfDay();
        $finder = $moving !== null ? $this->finder->ignoring($moving) : $this->finder;
        $slots = $finder->slots($workspace, $service, $from, $day !== null ? 1 : self::SEARCH_DAYS, $moving?->staff_id, $now);

        $starts = [];
        foreach ($slots as $slot) {
            $local = $slot['start']->setTimezone($tz);
            if (! self::inPart($local, $part)) {
                continue;
            }
            $starts[$slot['start']->getTimestamp()] = $slot['start'];
        }
        ksort($starts);
        $starts = array_values($starts);

        if ($time !== null && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) === 1 && $day !== null) {
            $wanted = $day->setTime((int) $m[1], (int) $m[2]);
            foreach ($starts as $s) {
                if ($s->equalTo($wanted)) {
                    return [$s];
                }
            }
            usort($starts, fn (CarbonImmutable $a, CarbonImmutable $b) => abs($a->getTimestamp() - $wanted->getTimestamp()) <=> abs($b->getTimestamp() - $wanted->getTimestamp()));
            $near = array_slice($starts, 0, 3);
            usort($near, fn (CarbonImmutable $a, CarbonImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());

            return $near;
        }

        $gap = max(60, min(180, $service->duration_min)) * 60;
        $perDay = $day !== null ? $max : 3;
        $out = [];
        $count = [];
        $last = [];
        foreach ($starts as $s) {
            $key = $s->setTimezone($tz)->toDateString();
            if (($count[$key] ?? 0) >= $perDay || (isset($last[$key]) && $s->getTimestamp() - $last[$key] < $gap)) {
                continue;
            }
            $out[] = $s;
            $count[$key] = ($count[$key] ?? 0) + 1;
            $last[$key] = $s->getTimestamp();
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * Las opciones como botones (1 a 3) o como lista por día. `ids` arma el ID de cada horario.
     *
     * @param  list<CarbonImmutable>  $starts
     * @param  callable(CarbonImmutable): string  $ids
     * @return array<string, mixed>
     */
    public static function interactive(Workspace $workspace, BookingService $service, array $starts, callable $ids, string $body): array
    {
        $tz = self::timezone($workspace);
        if (count($starts) <= WhatsAppInteractive::BUTTONS_MAX) {
            return WhatsAppInteractive::buttons($body, array_map(fn (CarbonImmutable $s) => ['id' => $ids($s), 'title' => self::short($s, $tz)], $starts));
        }
        $sections = [];
        foreach ($starts as $s) {
            $local = PublicBooking::es($s->setTimezone($tz));
            $key = $local->toDateString();
            $sections[$key] ??= ['title' => $local->isoFormat('dddd D [de] MMM'), 'rows' => []];
            $sections[$key]['rows'][] = ['id' => $ids($s), 'title' => self::time($s, $tz), 'description' => __(':service · :min min', ['service' => $service->name, 'min' => $service->duration_min])];
        }

        return WhatsAppInteractive::list($body, __('Ver horarios'), array_values($sections));
    }

    /**
     * Los horarios para el modelo: lo que verá el cliente (sin IDs).
     *
     * @param  list<CarbonImmutable>  $starts
     * @return list<array{day: string, time: string, date: string}>
     */
    public static function labels(Workspace $workspace, array $starts): array
    {
        $tz = self::timezone($workspace);

        return array_map(function (CarbonImmutable $s) use ($tz): array {
            $local = PublicBooking::es($s->setTimezone($tz));

            return ['day' => $local->isoFormat('dddd D [de] MMMM'), 'time' => self::time($s, $tz), 'date' => $local->toDateString()];
        }, $starts);
    }

    /** «sábado 22 de noviembre a las 9:00 am», en la zona del negocio. */
    public static function when(Workspace $workspace, CarbonImmutable $start): string
    {
        $tz = self::timezone($workspace);
        $local = PublicBooking::es($start->setTimezone($tz));

        return __(':day a las :time', ['day' => $local->isoFormat('dddd D [de] MMMM'), 'time' => self::time($start, $tz)]);
    }

    public static function timezone(Workspace $workspace): string
    {
        return $workspace->timezone ?: 'America/Bogota';
    }

    private static function time(CarbonImmutable $start, string $tz): string
    {
        return PublicBooking::time($start, $tz);
    }

    /** Título corto para un botón (máximo 20): «sáb 22 · 9:00 am». */
    private static function short(CarbonImmutable $start, string $tz): string
    {
        $local = PublicBooking::es($start->setTimezone($tz));

        return rtrim($local->isoFormat('ddd'), '.').' '.$local->format('j').' · '.self::time($start, $tz);
    }

    private static function inPart(CarbonImmutable $local, string $part): bool
    {
        $hour = (int) $local->format('G');

        return match ($part) {
            'morning' => $hour < 12,
            'afternoon' => $hour >= 12 && $hour < 18,
            'evening' => $hour >= 18,
            default => true,
        };
    }
}
