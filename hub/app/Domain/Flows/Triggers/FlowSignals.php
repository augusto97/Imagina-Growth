<?php

declare(strict_types=1);

namespace App\Domain\Flows\Triggers;

use App\Domain\Commerce\Models\Order;
use App\Domain\Flows\Actions\FinishRun;
use App\Domain\Flows\Engine\FlowEngine;
use App\Domain\Flows\Graph\Filters;
use App\Domain\Flows\Jobs\AdvanceFlowRun;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Flows\Support\EventProps;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Un evento de un contacto que importa a sus runs vivos (corre dentro del
 * contexto del espacio de trabajo):
 *
 * - **Meta:** si es el evento meta del flujo (con sus filtros), el run termina
 *   `goal_reached` y guarda la conversión (pedido e ingreso) para la analítica.
 * - **«Esperar hasta un evento»:** despierta los runs que lo esperan y siguen por
 *   la rama `event`.
 */
final class FlowSignals
{
    public function __construct(private readonly FinishRun $finish) {}

    /** @param array<string, mixed> $props */
    public function onEvent(string $contactId, string $event, string $eventId, array $props): void
    {
        $runs = FlowRun::query()->where('contact_id', $contactId)->whereIn('status', FlowRun::LIVE)->get();
        if ($runs->isEmpty()) {
            return;
        }
        $flows = Flow::query()->whereKey($runs->pluck('flow_id')->unique()->all())->get()->keyBy('id');
        foreach ($runs as $run) {
            if ($run->isTest()) {
                continue;
            }
            $flow = $flows->get($run->flow_id);
            if (! $flow instanceof Flow) {
                continue;
            }
            try {
                if ($flow->goalEvent() === $event && Filters::matches((array) ($flow->goal['filters'] ?? []), $props)) {
                    $this->goal($run, $event, $eventId, $props);

                    continue;
                }
                if ($run->status === FlowRun::WAITING && $run->waiting_for === $event) {
                    $this->wake($run, $event, $eventId, $props);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @param array<string, mixed> $props */
    private function goal(FlowRun $run, string $event, string $eventId, array $props): void
    {
        $orderId = $props['order']['order_id'] ?? $props['order_id'] ?? null;
        $order = is_scalar($orderId) ? Order::query()->where('external_id', (string) $orderId)->orderByDesc('created_at')->first(['id', 'total_ws']) : null;
        $lock = Cache::lock(FlowEngine::lockKey($run->id), 30);
        $lock->block(10);
        try {
            $run->refresh();
            $this->finish->handle($run, FlowRun::COMPLETED, 'goal_reached', ['_goal' => array_filter([
                'event' => $event,
                'event_id' => $eventId,
                'order_id' => $order?->id,
                'revenue_ws' => $order !== null ? (string) $order->total_ws : null,
                'at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null)], $event);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $props */
    private function wake(FlowRun $run, string $event, string $eventId, array $props): void
    {
        $nodeId = (string) $run->current_node_id;
        $attempt = $run->attemptFor($nodeId);
        $step = FlowRunStep::query()->where('run_id', $run->id)->where('node_id', $nodeId)->where('attempt', $attempt)->first();
        if (! Filters::matches((array) ($step?->details['filters'] ?? []), $props)) {
            return;
        }
        $lock = Cache::lock(FlowEngine::lockKey($run->id), 30);
        $lock->block(10);
        try {
            $run->refresh();
            if ($run->status !== FlowRun::WAITING || $run->waiting_for !== $event) {
                return;
            }
            $context = (array) ($run->context ?? []);
            $context['_woken'] = ['node' => $nodeId, 'attempt' => $attempt, 'event' => $event, 'event_id' => $eventId, 'props' => EventProps::sanitize($props)];
            $run->forceFill(['context' => $context, 'waiting_for' => null, 'status' => FlowRun::ACTIVE, 'next_run_at' => now()])->save();
        } finally {
            $lock->release();
        }
        AdvanceFlowRun::dispatch($run->workspace_id, $run->id);
    }
}
