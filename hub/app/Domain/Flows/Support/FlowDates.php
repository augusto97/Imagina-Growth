<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Durations;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fechas de referencia de un run para «esperar hasta antes o después de una fecha» (nodo `wait` con
 * `mode: until_date`) y «¿falta más de…?» (nodo `condition` con `mode: date`):
 *
 * - `anchor: appointment`: la hora **actual** de la cita del disparador (`props.appointment_id`): si la persona
 *   la reprograma, la espera se mueve sola; si se cancela, se cumple o no asistió, la cita ya no está activa.
 * - `anchor: prop`: una fecha de las propiedades del disparador (`path`, p. ej. `fields.event_date` de un
 *   formulario de inscripción a un evento), en la zona horaria del espacio de trabajo si no trae zona.
 *
 * `offset` es una duración (`{value, unit}`, hasta 365 días) y `direction` dice si es antes o después.
 */
final class FlowDates
{
    public const ANCHORS = ['appointment', 'prop'];

    public const DIRECTIONS = ['before', 'after'];

    /**
     * @param  array<string, mixed>  $config
     * @return array{state: 'ok'|'missing'|'inactive', at: CarbonImmutable|null}
     */
    public static function anchor(RunState $state, array $config): array
    {
        $props = (array) (($state->run->context ?? [])['props'] ?? []);
        if (($config['anchor'] ?? 'appointment') === 'appointment') {
            $id = $props['appointment_id'] ?? null;
            $appointment = is_string($id) ? Appointment::findByPublicId($id) : null;
            if ($appointment === null) {
                return ['state' => 'missing', 'at' => null];
            }

            return $appointment->isActive()
                ? ['state' => 'ok', 'at' => CarbonImmutable::instance($appointment->starts_at)->utc()]
                : ['state' => 'inactive', 'at' => CarbonImmutable::instance($appointment->starts_at)->utc()];
        }
        $value = data_get($props, (string) ($config['path'] ?? ''));
        if (! is_string($value) || trim($value) === '') {
            return ['state' => 'missing', 'at' => null];
        }
        try {
            $at = CarbonImmutable::parse(trim($value), (string) ($state->workspace->timezone ?: 'America/Bogota'))->utc();
        } catch (Throwable) {
            return ['state' => 'missing', 'at' => null];
        }

        return ['state' => 'ok', 'at' => $at];
    }

    /**
     * La fecha de referencia movida por el desplazamiento del nodo.
     *
     * @param  array<string, mixed>  $config
     */
    public static function target(CarbonImmutable $at, array $config): CarbonImmutable
    {
        $seconds = Durations::seconds($config['offset'] ?? null) ?? 0;

        return ($config['direction'] ?? 'before') === 'after' ? $at->addSeconds($seconds) : $at->subSeconds($seconds);
    }

    /**
     * Errores de configuración (vacío = válida).
     *
     * @param  array<string, mixed>  $c
     */
    public static function invalid(array $c): ?string
    {
        $anchor = $c['anchor'] ?? 'appointment';
        if (! in_array($anchor, self::ANCHORS, true) || ! in_array($c['direction'] ?? 'before', self::DIRECTIONS, true)) {
            return __('Elige la fecha de referencia (la cita o un dato del disparador) y si es antes o después.');
        }
        if ($anchor === 'prop' && preg_match('/^[a-z_][a-z0-9_]{0,40}(\.[a-z0-9_]{1,40}){0,4}$/', (string) ($c['path'] ?? '')) !== 1) {
            return __('Escribe qué dato del disparador tiene la fecha (por ejemplo, fields.event_date).');
        }
        if (isset($c['offset']) && Durations::seconds($c['offset']) === null) {
            return __('La diferencia debe estar entre 1 minuto y 365 días.');
        }

        return null;
    }
}
