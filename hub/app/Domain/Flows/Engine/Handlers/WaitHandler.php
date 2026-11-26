<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Durations;
use App\Domain\Flows\Support\FlowDates;
use Carbon\CarbonImmutable;

/**
 * Espera un tiempo (`duration`), hasta una hora de ciertos días (`until_time`,
 * en la zona del espacio de trabajo) o hasta antes o después de una fecha
 * (`until_date`: la cita del disparador o un dato del evento, FlowDates). En
 * una prueba no espera.
 */
final class WaitHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if (($node['config']['mode'] ?? 'duration') === 'until_date') {
            return $this->untilDate($state, (array) $node['config']);
        }
        $previous = $state->stepDetails()['until'] ?? null;
        if ($state->step !== null && is_string($previous)) {
            $until = CarbonImmutable::parse($previous);

            return $state->now->greaterThanOrEqualTo($until) ? Outcome::next(details: ['waited_until' => $previous]) : Outcome::wait($until);
        }
        if ($state->isTest()) {
            return Outcome::next(details: ['skipped_for_test' => true]);
        }
        $config = $node['config'];
        $until = ($config['mode'] ?? 'duration') === 'until_time'
            ? self::nextTime($state->now, (string) ($state->workspace->timezone ?: 'America/Bogota'), (int) ($config['hour'] ?? 9), (int) ($config['minute'] ?? 0), array_values((array) ($config['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7])))
            : (Durations::after($state->now, $config['duration'] ?? null) ?? $state->now->addHour());

        return Outcome::wait($until);
    }

    /**
     * Hasta N horas o días antes (o después) de la fecha de referencia. Se recalcula cada vez que el run despierta:
     * si la cita se reprogramó, sigue esperando hasta la nueva hora; si se canceló, se cumplió o no asistió, el run
     * sale (`filter`, no cuenta para la reentrada). Si la fecha ya pasó o no está, `if_past` decide: `continue`
     * (sigue enseguida) o `exit` (sale).
     *
     * @param  array<string, mixed>  $config
     */
    private function untilDate(RunState $state, array $config): Outcome
    {
        $anchor = FlowDates::anchor($state, $config);
        if ($anchor['state'] === 'inactive') {
            return Outcome::exit('filter');
        }
        $ifPast = ($config['if_past'] ?? 'continue') === 'exit' ? 'exit' : 'continue';
        if ($anchor['at'] === null) {
            return $ifPast === 'exit' ? Outcome::exit('filter') : Outcome::next(details: ['missing_date' => true]);
        }
        $target = FlowDates::target($anchor['at'], $config);
        if ($state->step !== null && is_string($state->stepDetails()['until'] ?? null)) {
            // Ya esperó: sigue si llegó la hora (aunque la cita se haya movido antes) o vuelve a esperar si se movió después.
            return $state->now->greaterThanOrEqualTo($target) ? Outcome::next(details: ['waited_until' => $target->toIso8601String()]) : Outcome::wait($target);
        }
        if ($state->isTest()) {
            return Outcome::next(details: ['skipped_for_test' => true]);
        }
        if ($target->lessThanOrEqualTo($state->now)) {
            return $ifPast === 'exit' ? Outcome::exit('filter') : Outcome::next(details: ['late' => true]);
        }

        return Outcome::wait($target);
    }

    /** @param list<mixed> $weekdays */
    public static function nextTime(CarbonImmutable $now, string $timezone, int $hour, int $minute, array $weekdays): CarbonImmutable
    {
        $local = $now->setTimezone($timezone);
        $allowed = array_map('intval', $weekdays ?: [1, 2, 3, 4, 5, 6, 7]);
        for ($i = 0; $i <= 7; $i++) {
            $candidate = $local->addDays($i)->setTime($hour, $minute);
            if ($candidate->greaterThan($local) && in_array($candidate->dayOfWeekIso, $allowed, true)) {
                return $candidate->utc();
            }
        }

        return $local->addWeek()->setTime($hour, $minute)->utc();
    }
}
