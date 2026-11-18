<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Durations;
use Carbon\CarbonImmutable;

/**
 * Espera un tiempo (`duration`) o hasta una hora de ciertos días (`until_time`,
 * en la zona del espacio de trabajo). En una prueba no espera.
 */
final class WaitHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
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
            ? self::nextTime($state->now, (string) ($state->workspace->timezone ?: 'America/Bogota'), (int) ($config['hour'] ?? 9), (int) ($config['minute'] ?? 0), (array) ($config['weekdays'] ?? [1, 2, 3, 4, 5, 6, 7]))
            : (Durations::after($state->now, $config['duration'] ?? null) ?? $state->now->addHour());

        return Outcome::wait($until);
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
