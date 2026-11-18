<?php

declare(strict_types=1);

namespace App\Domain\Consent\Policy;

use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;

/**
 * Franja en la que la ley permite mensajes comerciales, por país y en la hora
 * local del contacto (cumplimiento.md §4 a §7).
 *
 * - Colombia (Ley 2300 de 2023): lunes a viernes de 7:00 a 19:00 y sábados de
 *   8:00 a 15:00. Nunca domingos ni festivos.
 * - México: lunes a sábado de 9:00 a 20:00, sin festivos oficiales **[validar]**.
 * - Venezuela y el resto: el estándar de Colombia (cumplimiento.md §7).
 *
 * El final de cada franja es **exclusivo** (a las 19:00:00 ya no se envía): es la
 * lectura más prudente de «de 7:00 a. m. a 7:00 p. m.». Los festivos son los del
 * país del contacto (Holidays, con las excepciones de holiday_overrides).
 */
final class LegalWindow
{
    /** @var array<string, array<int, array{0: string, 1: string}>> país → día ISO (1 = lunes) → [desde, hasta) */
    public const WINDOWS = [
        'CO' => [1 => ['07:00', '19:00'], 2 => ['07:00', '19:00'], 3 => ['07:00', '19:00'], 4 => ['07:00', '19:00'], 5 => ['07:00', '19:00'], 6 => ['08:00', '15:00']],
        'MX' => [1 => ['09:00', '20:00'], 2 => ['09:00', '20:00'], 3 => ['09:00', '20:00'], 4 => ['09:00', '20:00'], 5 => ['09:00', '20:00'], 6 => ['09:00', '20:00']],
    ];

    /** @var array<string, string> */
    public const TIMEZONES = ['CO' => 'America/Bogota', 'MX' => 'America/Mexico_City', 'VE' => 'America/Caracas'];

    /** @return array<int, array{0: string, 1: string}> */
    public static function windowsFor(string $country): array
    {
        return self::WINDOWS[strtoupper($country)] ?? self::WINDOWS['CO'];
    }

    public static function timezoneFor(string $country, ?string $timezone = null): string
    {
        if ($timezone !== null && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return self::TIMEZONES[strtoupper($country)] ?? 'America/Bogota';
    }

    /**
     * null si `at` está dentro de la franja; si no, el motivo (`sunday`, `holiday`
     * u `outside_hours`).
     */
    public static function closedReason(string $country, CarbonImmutable $at, ?string $timezone = null): ?string
    {
        $local = $at->setTimezone(self::timezoneFor($country, $timezone));
        $windows = self::windowsFor($country);
        if (Holidays::isHoliday(strtoupper($country), $local)) {
            return PolicyDecision::HOLIDAY;
        }
        $window = $windows[$local->dayOfWeekIso] ?? null;
        if ($window === null) {
            return $local->dayOfWeekIso === 7 ? PolicyDecision::SUNDAY : PolicyDecision::OUTSIDE_HOURS;
        }
        $time = $local->format('H:i:s');

        return $time >= $window[0].':00' && $time < $window[1].':00' ? null : PolicyDecision::OUTSIDE_HOURS;
    }

    public static function isOpen(string $country, CarbonImmutable $at, ?string $timezone = null): bool
    {
        return self::closedReason($country, $at, $timezone) === null;
    }

    /**
     * El primer instante permitido desde `at` (el mismo `at` si ya está abierto).
     * Devuelve UTC. Busca hasta 21 días (ninguna combinación de festivos cierra tanto).
     */
    public static function nextOpen(string $country, CarbonImmutable $at, ?string $timezone = null): CarbonImmutable
    {
        if (self::isOpen($country, $at, $timezone)) {
            return $at->utc();
        }
        $tz = self::timezoneFor($country, $timezone);
        $local = $at->setTimezone($tz);
        $windows = self::windowsFor($country);
        for ($i = 0; $i <= 21; $i++) {
            $day = $local->addDays($i)->startOfDay();
            $window = $windows[$day->dayOfWeekIso] ?? null;
            if ($window === null || Holidays::isHoliday(strtoupper($country), $day)) {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $window[0]));
            $open = $day->setTime($h, $m);
            [$eh, $em] = array_map('intval', explode(':', $window[1]));
            $close = $day->setTime($eh, $em);
            if ($i === 0 && $local->greaterThanOrEqualTo($close)) {
                continue;
            }

            return ($i === 0 && $local->greaterThan($open) ? $local : $open)->utc();
        }

        return $local->addDays(22)->utc();
    }

    /** Inicio de la franja del día siguiente (local) o el siguiente día permitido, para «ya recibió hoy». */
    public static function nextDayOpen(string $country, CarbonImmutable $at, ?string $timezone = null): CarbonImmutable
    {
        $local = $at->setTimezone(self::timezoneFor($country, $timezone));

        return self::nextOpen($country, $local->addDay()->startOfDay(), $timezone);
    }

    /** Inicio de la semana siguiente (lunes local) o el siguiente día permitido, para el tope semanal. */
    public static function nextWeekOpen(string $country, CarbonImmutable $at, ?string $timezone = null): CarbonImmutable
    {
        $local = $at->setTimezone(self::timezoneFor($country, $timezone));

        return self::nextOpen($country, $local->startOfWeek(CarbonImmutable::MONDAY)->addWeek(), $timezone);
    }
}
