<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Actions;

use App\Domain\Strategies\Actions\ActivateStrategy;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\PlanRequired;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Paso 5 del asistente (docs/diseno/rediseno-v2.md §4): prende de una vez las estrategias recomendadas para el tipo de
 * negocio (o las de esa lista que la persona dejó encendidas) con ActivateStrategy, una por una. Lo que no se puede
 * prender todavía vuelve con su motivo y sus requisitos; nunca falla toda la operación por una.
 */
final class ActivateRecommendedStrategies
{
    public function __construct(private readonly StrategyCatalog $catalog, private readonly ActivateStrategy $activate) {}

    /**
     * @param  list<string>|null  $keys  las elegidas (deben estar entre las recomendadas); null = todas las recomendadas
     * @return list<array{key: string, result: string, message?: string, requirements?: list<array<string, mixed>>}>
     */
    public function handle(Workspace $workspace, ?array $keys, ?string $userId): array
    {
        $recommended = $this->catalog->recommended(StrategyContext::for($workspace), 3, true);
        $chosen = $keys === null ? $recommended : array_values(array_intersect($recommended, $keys));
        $out = [];
        foreach ($chosen as $key) {
            $definition = StrategyRegistry::find($key);
            if ($definition === null) {
                continue;
            }
            foreach ($definition->abilities as $ability) {
                if (! Gate::allows('ws.'.$ability)) {
                    $out[] = ['key' => $key, 'result' => 'forbidden', 'message' => __('This action is unauthorized.')];

                    continue 2;
                }
            }
            try {
                $result = $this->activate->handle($workspace, $key, [], $userId);
                $out[] = ['key' => $key, 'result' => $result['activated'] ? 'activated' : 'already_active'];
            } catch (StrategyNotReady $e) {
                $out[] = ['key' => $key, 'result' => 'needs_setup', 'message' => $e->getMessage(), 'requirements' => array_map(fn (Requirement $r) => $r->toArray(), $e->requirements)];
            } catch (PlanRequired $e) {
                $out[] = ['key' => $key, 'result' => 'not_in_plan', 'message' => $e->getMessage()];
            }
        }

        return $out;
    }
}
