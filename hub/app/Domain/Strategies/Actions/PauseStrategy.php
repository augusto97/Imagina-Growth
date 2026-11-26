<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Actions;

use App\Domain\Strategies\Models\WorkspaceStrategy;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\StrategyCache;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Pausa una estrategia sin borrar nada: cada bloque se detiene con la acción de su módulo (el flujo queda en pausa y
 * sus recorridos esperan, el widget deja de mostrarse, el programa no da puntos…) y guarda lo que hace falta para
 * reanudarla igual. Idempotente; queda en la auditoría.
 */
final class PauseStrategy
{
    public function __construct(private readonly StrategyCatalog $catalog, private readonly RecordAuditLog $audit) {}

    /** @return array{paused: bool, detail: array<string, mixed>} */
    public function handle(Workspace $workspace, string $key, ?string $userId): array
    {
        $definition = StrategyRegistry::find($key) ?? throw new InvalidArgumentException("Estrategia desconocida: {$key}");

        return Cache::lock('ws:'.$workspace->id.':strategy:'.$key, 60)->block(15, function () use ($workspace, $definition, $userId): array {
            $ctx = StrategyContext::for($workspace);
            if (! in_array($this->catalog->evaluate($definition, $ctx)['status'], ['active', 'attention'], true)) {
                return ['paused' => false, 'detail' => $this->catalog->detail($workspace, $definition->key) ?? []];
            }
            $settings = [];
            foreach ($definition->blocks as $block) {
                $settings = array_replace($settings, $block->pause($ctx->fresh(), $userId));
            }
            $row = WorkspaceStrategy::query()->firstOrNew(['strategy_key' => $definition->key]);
            $row->fill([
                'status' => WorkspaceStrategy::PAUSED,
                'settings' => array_replace((array) ($row->settings ?? []), $settings),
                'paused_at' => now(),
                'paused_by' => $userId,
            ])->save();
            $this->audit->handle('strategy.paused', $row, ['strategy' => $definition->key], $workspace->id, null, $userId);
            StrategyCache::bump($workspace->id);

            return ['paused' => true, 'detail' => $this->catalog->detail($workspace, $definition->key) ?? []];
        });
    }
}
