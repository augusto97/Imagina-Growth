<?php

declare(strict_types=1);

namespace App\Domain\Flows\Graph;

use Carbon\CarbonImmutable;

/** Duraciones de las esperas: `{value: 3, unit: "hours"}`. */
final class Durations
{
    public const UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];

    /** Segundos entre 1 minuto y 365 días, o null si no es válida. */
    public static function seconds(mixed $duration): ?int
    {
        if (! is_array($duration)) {
            return null;
        }
        $value = $duration['value'] ?? null;
        $unit = $duration['unit'] ?? null;
        if (! is_numeric($value) || ! is_string($unit) || ! isset(self::UNITS[$unit])) {
            return null;
        }
        $seconds = (int) round((float) $value * self::UNITS[$unit]);

        return $seconds >= 60 && $seconds <= 365 * 86400 ? $seconds : null;
    }

    public static function after(CarbonImmutable $from, mixed $duration): ?CarbonImmutable
    {
        $seconds = self::seconds($duration);

        return $seconds === null ? null : $from->addSeconds($seconds);
    }
}
