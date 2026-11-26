<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Actions;

use App\Domain\Strategies\Blocks\FlowBlock;
use App\Domain\Strategies\Models\WorkspaceStrategy;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\PlanRequired;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyCache;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Deja una estrategia funcionando con los valores recomendados (docs/diseno/rediseno-v2.md §6). Revisa el plan y los
 * requisitos, prende su bloque principal con las acciones de su módulo y, si se puede, los bloques de apoyo (los
 * flujos de VIP, puntos por vencer, bienvenida o invitar a referir); guarda la decisión y queda en la auditoría.
 * Idempotente: una estrategia ya activa no se vuelve a crear, y un candado por estrategia evita duplicados si llegan
 * dos clics a la vez. Solo prende bloques que ya cumplen consentimiento, horario legal y frecuencia por su cuenta.
 */
final class ActivateStrategy
{
    public function __construct(private readonly StrategyCatalog $catalog, private readonly RecordAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $options  ajustes de la activación (`google_review_url`, `wa_number`)
     * @return array{activated: bool, skipped: list<string>, detail: array<string, mixed>}
     *
     * @throws PlanRequired
     * @throws StrategyNotReady
     */
    public function handle(Workspace $workspace, string $key, array $options, ?string $userId): array
    {
        $definition = StrategyRegistry::find($key) ?? throw new InvalidArgumentException("Estrategia desconocida: {$key}");

        return Cache::lock('ws:'.$workspace->id.':strategy:'.$key, 60)->block(15, function () use ($workspace, $definition, $options, $userId): array {
            $ctx = StrategyContext::for($workspace);
            $eval = $this->catalog->evaluate($definition, $ctx, $options);
            if ($eval['status'] === 'coming_soon') {
                throw new StrategyNotReady(__('strategies.coming_soon_hint'), [], [], null, 'coming_soon');
            }
            if ($eval['status'] === 'not_in_plan') {
                $plans = $this->catalog->present($definition, $ctx, [], [])['plans'];
                throw new PlanRequired($eval['missing_modules'], $plans);
            }
            if (in_array($eval['status'], ['active', 'attention'], true)) {
                return ['activated' => false, 'skipped' => [], 'detail' => $this->catalog->detail($workspace, $definition->key) ?? []];
            }
            $blocking = array_values(array_filter($eval['requirements'], fn (Requirement $r) => $r->blocks()));
            if ($blocking !== []) {
                throw new StrategyNotReady(__('strategies.errors.needs_setup'), $blocking, [], $blocking[0]->to);
            }

            $refs = $definition->primary()->activate($ctx, $options, $userId);
            $skipped = [];
            foreach (array_slice($definition->blocks, 1) as $block) {
                if ($block instanceof FlowBlock && ! $block->ready($ctx->fresh())) {
                    $skipped[] = $block->libraryKey;

                    continue;
                }
                try {
                    $refs = array_merge_recursive($refs, $block->activate($ctx->fresh(), $options, $userId));
                } catch (StrategyNotReady) {
                    $skipped[] = $block instanceof FlowBlock ? $block->libraryKey : $block->kind();
                }
            }

            $row = WorkspaceStrategy::query()->firstOrNew(['strategy_key' => $definition->key]);
            $row->fill([
                'status' => WorkspaceStrategy::ACTIVE,
                'refs' => self::mergeRefs((array) ($row->refs ?? []), $refs),
                'activated_at' => now(),
                'activated_by' => $userId,
            ])->save();
            $this->audit->handle('strategy.activated', $row, ['strategy' => $definition->key, 'skipped' => $skipped], $workspace->id, null, $userId);
            StrategyCache::bump($workspace->id);

            return ['activated' => true, 'skipped' => $skipped, 'detail' => $this->catalog->detail($workspace, $definition->key) ?? []];
        });
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, list<string>>
     */
    private static function mergeRefs(array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $kind) {
            $out[(string) $kind] = array_values(array_unique(array_map('strval', [...(array) ($old[$kind] ?? []), ...(array) ($new[$kind] ?? [])])));
        }

        return $out;
    }
}
