<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Flows\Triggers\TriggerIndex;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Activar, pausar o archivar. Pausar deja los runs donde van (al reactivar
 * siguen); archivar los termina con motivo `manual`.
 */
final class ChangeFlowStatus
{
    public function __construct(
        private readonly TriggerIndex $index,
        private readonly FinishRun $finish,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @throws InvalidFlow */
    public function handle(Flow $flow, string $status): Flow
    {
        if ($status === Flow::ACTIVE) {
            if ($flow->current_version_id === null) {
                throw new InvalidFlow(['status' => __('Publica el flujo antes de activarlo.')]);
            }
            if (($flow->trigger['type'] ?? null) === null) {
                throw new InvalidFlow(['trigger' => __('Elige el disparador antes de activarlo.')]);
            }
            $flow->forceFill(['status' => Flow::ACTIVE, 'activated_at' => $flow->activated_at ?? now()])->save();
        } elseif ($status === Flow::PAUSED) {
            $flow->forceFill(['status' => Flow::PAUSED])->save();
        } elseif ($status === Flow::ARCHIVED) {
            $flow->forceFill(['status' => Flow::ARCHIVED])->save();
            FlowRun::query()->where('flow_id', $flow->id)->whereIn('status', FlowRun::LIVE)->chunkById(200, function ($runs): void {
                foreach ($runs as $run) {
                    $this->finish->handle($run, FlowRun::EXITED, 'manual');
                }
            });
        }
        $this->index->forget($flow->workspace_id);
        $this->audit->handle(match ($status) {
            Flow::ACTIVE => 'flow.activated', Flow::PAUSED => 'flow.paused', default => 'flow.archived'
        }, $flow, [], $flow->workspace_id);

        return $flow;
    }
}
