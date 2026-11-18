<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Actions\FinishRun;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Flows\Triggers\TriggerIndex;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Avanza un run por su grafo (hub/CLAUDE.md §7). Corre con un candado por run,
 * así dos workers nunca lo procesan a la vez. Cada nodo se registra en
 * flow_run_steps con (run, nodo, intento): si el job se reintenta, un paso ya
 * cerrado no repite su efecto y se sigue por la rama que tomó. Las esperas, los
 * aplazamientos de la política y las esperas por evento dejan el run `waiting`
 * con `next_run_at`; el scheduler lo retoma.
 */
final class FlowEngine
{
    /** Pasos por ejecución: lo que sobre sigue en el siguiente tick. */
    public const MAX_STEPS = 50;

    public function __construct(
        private readonly NodeHandlers $handlers,
        private readonly FinishRun $finish,
        private readonly TriggerIndex $index,
    ) {}

    public static function lockKey(string $runId): string
    {
        return 'flowrun:'.$runId;
    }

    public function advance(string $runId): void
    {
        $lock = Cache::lock(self::lockKey($runId), 120);
        if (! $lock->get()) {
            return; // otro worker lo está procesando; el scheduler lo retoma
        }
        try {
            $this->advanceLocked($runId);
        } finally {
            $lock->release();
        }
    }

    private function advanceLocked(string $runId): void
    {
        $run = FlowRun::query()->find($runId);
        if ($run === null || ! $run->isLive()) {
            return;
        }
        $flow = Flow::query()->find($run->flow_id);
        $workspace = Workspace::query()->find($run->workspace_id);
        $version = FlowVersion::query()->find($run->flow_version_id);
        if ($flow === null || $workspace === null || $version === null) {
            $this->finish->handle($run, FlowRun::FAILED, 'failed');

            return;
        }
        if (! $run->isTest() && $flow->status !== Flow::ACTIVE) {
            if ($flow->status === Flow::ARCHIVED) {
                $this->finish->handle($run, FlowRun::EXITED, 'flow_paused');
            } else {
                // Pausado: queda donde está; al reactivarse el scheduler lo retoma.
                $run->forceFill(['next_run_at' => now()->addMinutes(15)])->save();
            }

            return;
        }
        $graph = $version->parsed();
        $contact = Contact::query()->find($run->contact_id);
        if ($contact === null) {
            $this->finish->handle($run, FlowRun::EXITED, 'failed');

            return;
        }
        $now = CarbonImmutable::now();
        if ($run->status !== FlowRun::ACTIVE) {
            $run->forceFill(['status' => FlowRun::ACTIVE])->save();
        }

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $nodeId = $run->current_node_id;
            if ($nodeId === null) {
                $this->finish->handle($run, FlowRun::COMPLETED, 'completed');

                return;
            }
            $node = $graph->node($nodeId);
            if ($node === null) {
                $this->finish->handle($run, FlowRun::FAILED, 'failed');

                return;
            }
            $attempt = $run->attemptFor($nodeId);
            $step = FlowRunStep::query()->where('run_id', $run->id)->where('node_id', $nodeId)->where('attempt', $attempt)->first();

            // Paso ya cerrado (reintento del job): no se repite, se sigue por su rama.
            if ($step !== null && in_array($step->outcome, Outcome::DONE, true)) {
                if ($step->outcome === 'exit') {
                    $this->finish->handle($run, FlowRun::COMPLETED, 'exit_node');

                    return;
                }
                $details = (array) $step->details;
                $this->moveTo($run, $graph->next($nodeId, isset($details['branch']) ? (string) $details['branch'] : null, (bool) ($details['fallback'] ?? false)));

                continue;
            }

            $handler = $this->handlers->for($node['type']);
            if ($handler === null) {
                $this->record($run, $nodeId, $attempt, 'failed', ['reason' => 'unsupported_node'], $step);
                $this->finish->handle($run, FlowRun::FAILED, 'failed');

                return;
            }
            $outcome = $handler->handle(new RunState($workspace, $flow, $run, $graph, $contact, $attempt, $step, $now), $node);

            switch ($outcome->kind) {
                case Outcome::CONTINUE:
                    $this->record($run, $nodeId, $attempt, $outcome->record, $outcome->details + ['branch' => $outcome->branch, 'fallback' => $outcome->fallback], $step);
                    if ($node['type'] === 'wait_event') {
                        $this->consumeWoken($run, $outcome->branch === 'event');
                    }
                    $this->moveTo($run, $graph->next($nodeId, $outcome->branch, $outcome->fallback));

                    break;
                case Outcome::EXIT:
                    $this->record($run, $nodeId, $attempt, 'exit', $outcome->details, $step);
                    $reason = (string) ($outcome->details['reason'] ?? 'exit_node');
                    $this->finish->handle($run, $reason === 'filter' ? FlowRun::EXITED : FlowRun::COMPLETED, $reason);

                    return;
                default: // wait, wait_event, deferred
                    $this->record($run, $nodeId, $attempt, $outcome->record, $outcome->details, $step);
                    $run->forceFill([
                        'status' => FlowRun::WAITING,
                        'next_run_at' => $outcome->until,
                        'waiting_for' => $outcome->kind === Outcome::WAIT_EVENT ? $outcome->event : null,
                    ])->save();
                    if ($outcome->kind === Outcome::WAIT_EVENT && $outcome->event !== null) {
                        $this->index->addWake($run->workspace_id, $outcome->event);
                    }

                    return;
            }
        }
        // Demasiados pasos seguidos: sigue en el siguiente tick.
        $run->forceFill(['next_run_at' => now()])->save();
    }

    private function moveTo(FlowRun $run, ?string $next): void
    {
        $context = (array) ($run->context ?? []);
        if ($next !== null) {
            $attempts = (array) ($context['_attempt'] ?? []);
            $attempts[$next] = (int) ($attempts[$next] ?? 0) + 1;
            $context['_attempt'] = $attempts;
        }
        $run->forceFill(['current_node_id' => $next, 'context' => $context, 'waiting_for' => null])->save();
    }

    /**
     * El evento que despertó al run queda en `last_event` ({event, event_id, props})
     * para las condiciones que siguen (p. ej. qué botón respondió).
     */
    private function consumeWoken(FlowRun $run, bool $keep): void
    {
        $context = (array) ($run->context ?? []);
        $woken = $context['_woken'] ?? null;
        unset($context['_woken']);
        if ($keep && is_array($woken)) {
            $context['last_event'] = ['event' => $woken['event'] ?? null, 'event_id' => $woken['event_id'] ?? null, 'props' => $woken['props'] ?? []];
        }
        $run->context = $context;
    }

    /** @param array<string, mixed> $details */
    private function record(FlowRun $run, string $nodeId, int $attempt, string $outcome, array $details, ?FlowRunStep $existing): void
    {
        $details = array_filter($details, fn ($v) => $v !== null && $v !== false);
        if ($existing !== null) {
            $existing->forceFill(['outcome' => $outcome, 'details' => $details, 'executed_at' => now()])->save();

            return;
        }
        FlowRunStep::query()->create([
            'workspace_id' => $run->workspace_id,
            'run_id' => $run->id,
            'node_id' => $nodeId,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'details' => $details,
            'executed_at' => now(),
        ]);
    }
}
