<?php

declare(strict_types=1);

namespace App\Domain\Backups\Support;

use Carbon\CarbonImmutable;

/**
 * Próximo horario de cada tarea, con los mismos horarios que los timers de systemd (UTC):
 * infra/systemd/growth-backup-{mariadb,clickhouse,verify}.timer. `growth.backups.schedule` los repite: «03:15»
 * todos los días o «sun 05:00» una vez por semana.
 */
final class BackupSchedule
{
    private const DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** @return array<string, string|null> tarea → fecha ISO 8601 en UTC */
    public function next(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $out = [];
        foreach ((array) config('growth.backups.schedule', []) as $job => $spec) {
            $out[(string) $job] = is_string($spec) ? $this->nextFor($spec, $now)?->toIso8601ZuluString() : null;
        }

        return $out;
    }

    private function nextFor(string $spec, CarbonImmutable $now): ?CarbonImmutable
    {
        if (preg_match('/^(?:(sun|mon|tue|wed|thu|fri|sat)\s+)?([01]\d|2[0-3]):([0-5]\d)$/D', trim(strtolower($spec)), $m) !== 1) {
            return null;
        }
        $day = $m[1] !== '' ? self::DAYS[$m[1]] : null;
        $at = $now->setTime((int) $m[2], (int) $m[3]);
        for ($i = 0; $i < 8; $i++) {
            if ($at->greaterThan($now) && ($day === null || $at->dayOfWeek === $day)) {
                return $at;
            }
            $at = $at->addDay();
        }

        return null;
    }
}
