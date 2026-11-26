<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Support;

use App\Domain\Workspaces\Models\Workspace;

/**
 * Horario de atención del negocio (Configuración → Tu negocio), en `workspaces.settings.business_hours`: los días
 * (1 = lunes … 7 = domingo) y la franja en la hora local del espacio de trabajo. Es el «horario del equipo» del agente
 * con IA cuando atiende solo fuera de horario (`handoff_rules.hours.mode = out_of_hours`): con él guardado, manda sobre
 * los días y horas propios del agente (que quedan como respaldo de los espacios de trabajo de antes).
 */
final class BusinessHours
{
    public const DEFAULT = ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00'];

    /**
     * Lo guardado, o null si el negocio todavía no lo definió.
     *
     * @return array{days: list<int>, start: string, end: string}|null
     */
    public static function of(Workspace $workspace): ?array
    {
        $saved = $workspace->setting('business_hours');

        return is_array($saved) ? self::normalize($saved) : null;
    }

    /**
     * Días únicos y ordenados del 1 al 7; horas HH:MM.
     *
     * @param  array<string, mixed>  $value
     * @return array{days: list<int>, start: string, end: string}
     */
    public static function normalize(array $value): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($value['days'] ?? [])), fn (int $d) => $d >= 1 && $d <= 7)));
        sort($days);
        $time = fn (mixed $v, string $fallback): string => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : $fallback;

        return ['days' => $days, 'start' => $time($value['start'] ?? null, self::DEFAULT['start']), 'end' => $time($value['end'] ?? null, self::DEFAULT['end'])];
    }
}
