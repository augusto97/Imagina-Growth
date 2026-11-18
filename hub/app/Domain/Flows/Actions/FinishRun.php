<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cierra un run (completed, exited o failed) y emite flow.exited con el motivo
 * (salvo en las pruebas). Motivos: completed, goal_reached, exit_node, filter,
 * unsubscribed, manual, failed y flow_paused.
 */
final class FinishRun
{
    public function __construct(private readonly HubEvents $events) {}

    /** @param array<string, mixed> $remember datos para el contexto (p. ej. `_goal`) */
    public function handle(FlowRun $run, string $status, string $reason, array $remember = [], ?string $goalEvent = null): void
    {
        if (! $run->isLive()) {
            return;
        }
        $context = (array) ($run->context ?? []);
        unset($context['_woken']);
        $run->forceFill([
            'status' => $status,
            'exit_reason' => $reason,
            'ended_at' => now(),
            'next_run_at' => null,
            'waiting_for' => null,
            'context' => array_merge($context, $remember),
        ])->save();
        if ($run->isTest()) {
            return;
        }
        try {
            $flow = Flow::query()->find($run->flow_id);
            $workspace = Workspace::query()->find($run->workspace_id);
            if ($flow === null || $workspace === null) {
                return;
            }
            $version = FlowVersion::query()->whereKey($run->flow_version_id)->value('version');
            $this->events->emit($workspace, 'flow.exited', array_filter([
                'flow_id' => $flow->publicId(),
                'flow_name' => mb_substr($flow->name, 0, 200),
                'version' => is_numeric($version) ? (int) $version : null,
                'run_id' => $run->publicId(),
                'is_holdout' => $run->is_holdout,
                'reason' => $reason,
                'goal_event' => $goalEvent,
            ], fn ($v) => $v !== null), $run->contact_id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.flows.exit_event_failed', ['run' => $run->id]);
        }
    }
}
