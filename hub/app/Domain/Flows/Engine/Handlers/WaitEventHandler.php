<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Durations;
use Carbon\CarbonImmutable;

/**
 * «Esperar hasta un evento» con tiempo máximo: sale por `event` si el contacto
 * hace el evento (lo despierta TriggerRouter) o por `timeout`. En una prueba sale
 * por `timeout` sin esperar.
 */
final class WaitEventHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        $woken = $state->run->context['_woken'] ?? null;
        if (is_array($woken) && ($woken['node'] ?? null) === $node['id'] && (int) ($woken['attempt'] ?? 0) === $state->attempt) {
            return Outcome::next('event', details: ['event' => $woken['event'] ?? null, 'event_id' => $woken['event_id'] ?? null]);
        }
        $event = (string) ($node['config']['event'] ?? '');
        $previous = $state->stepDetails()['timeout_at'] ?? null;
        if ($state->step !== null && is_string($previous)) {
            $timeout = CarbonImmutable::parse($previous);

            return $state->now->greaterThanOrEqualTo($timeout) ? Outcome::next('timeout') : Outcome::waitForEvent($event, $timeout, ['filters' => $node['config']['filters'] ?? []]);
        }
        if ($state->isTest()) {
            return Outcome::next('timeout', details: ['skipped_for_test' => true]);
        }
        $timeout = Durations::after($state->now, $node['config']['timeout'] ?? null) ?? $state->now->addDay();

        return Outcome::waitForEvent($event, $timeout, ['filters' => $node['config']['filters'] ?? []]);
    }
}
