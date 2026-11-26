<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Queries;

use App\Domain\Billing\Models\Plan;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Strategies\Blocks\BlockState;
use App\Domain\Strategies\Blocks\FlowBlock;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\Requirements;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyDefinition;
use App\Domain\Strategies\Support\StrategyPotential;
use App\Domain\Strategies\Support\StrategyResults;
use App\Domain\Workspaces\Models\Workspace;

/**
 * El estado de cada estrategia en un espacio de trabajo y cómo se presenta (docs/diseno/rediseno-v2.md §6):
 *
 * - `not_in_plan`: el plan no incluye un módulo que necesita (se muestra el plan que lo incluye).
 * - `active`: su bloque principal está funcionando; `attention` si además tiene un problema (canal caído, plantilla
 *   rechazada, destino con errores, IA apagada…).
 * - `paused`: el bloque existe y se detuvo (o la persona la pausó).
 * - `needs_setup`: falta un requisito obligatorio (web, canal, plantilla aprobada…), con el enlace para resolverlo.
 * - `available`: lista para activarse con un clic.
 * - `coming_soon`: declarada, pero su flujo todavía no está en la biblioteca («Próximamente»); se vuelve disponible sola.
 *
 * El estado se lee en vivo de los bloques; el potencial y el resultado del mes vienen de su caché.
 */
final class StrategyCatalog
{
    public const STATUSES = ['available', 'needs_setup', 'active', 'paused', 'attention', 'not_in_plan', 'coming_soon'];

    /** Cuántas van en «Recomendadas para ti». */
    public const FOR_YOU = 6;

    public function __construct(private readonly StrategyPotential $potential, private readonly StrategyResults $results) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: string, state: BlockState, requirements: list<Requirement>, missing_modules: list<string>}
     */
    public function evaluate(StrategyDefinition $definition, StrategyContext $ctx, array $options = []): array
    {
        return $ctx->remember('eval:'.$definition->key.':'.md5((string) json_encode($options)), function () use ($definition, $ctx, $options): array {
            $available = StrategyRegistry::available($definition);
            $missing = array_values(array_filter($definition->modules, fn (string $m) => ! $ctx->module($m)));
            $requirements = [];
            foreach ($definition->modules as $module) {
                $requirements[] = new Requirement('module', ! in_array($module, $missing, true), true, 'facturacion', ['module' => __('strategies.modules.'.$module)]);
            }
            foreach ($definition->requires as $key) {
                $requirements[] = Requirements::check($key, $ctx, $options);
            }
            if (! $available) {
                // Su flujo todavía no está en la biblioteca: «Próximamente», sin estado de bloque que leer.
                return ['status' => 'coming_soon', 'state' => BlockState::off(null), 'requirements' => $requirements, 'missing_modules' => $missing];
            }
            foreach ($definition->primary()->requirements($ctx, $options) as $requirement) {
                $requirements[] = $requirement;
            }
            $state = $definition->primary()->state($ctx);
            if ($state->status === BlockState::OFF && $ctx->row($definition->key)?->status === 'paused') {
                $state = BlockState::paused($state->to, $state->refs);
            }
            $blocking = array_filter($requirements, fn (Requirement $r) => $r->blocks());
            $status = match (true) {
                $missing !== [] => 'not_in_plan',
                $state->isOn() => $state->problems !== [] ? 'attention' : 'active',
                $state->status === BlockState::PAUSED => 'paused',
                $blocking !== [] => 'needs_setup',
                default => 'available',
            };

            return ['status' => $status, 'state' => $state, 'requirements' => $requirements, 'missing_modules' => $missing];
        });
    }

    /**
     * El catálogo completo para `GET strategies`. Con `vertical`, «Recomendadas para ti» se ordena para esa vertical
     * en lugar de la del negocio.
     *
     * @return array<string, mixed>
     */
    public function catalog(Workspace $workspace, ?string $vertical = null): array
    {
        $ctx = StrategyContext::for($workspace);
        $potentials = $this->potential->forWorkspace($workspace);
        $results = $this->results($ctx);
        $recommended = $this->recommended($ctx, 3, false, $vertical);
        $items = [];
        foreach (StrategyRegistry::all() as $definition) {
            $items[] = $this->present($definition, $ctx, $potentials, $results['strategies'], in_array($definition->key, $recommended, true), $vertical);
        }

        return [
            'business_type' => $ctx->businessType(),
            'vertical' => $vertical ?? $ctx->vertical(),
            'currency' => (string) ($workspace->currency ?: 'COP'),
            'month' => $results['month'],
            'goals' => array_map(fn (string $g) => ['key' => $g, 'name' => __('strategies.goals.'.$g.'.name'), 'description' => __('strategies.goals.'.$g.'.description')], StrategyRegistry::GOALS),
            'recommended' => $recommended,
            'for_you' => $this->recommended($ctx, self::FOR_YOU, false, $vertical),
            'data' => $items,
        ];
    }

    /** @return array<string, mixed>|null el detalle de `GET strategies/{key}` */
    public function detail(Workspace $workspace, string $key, ?StrategyContext $ctx = null): ?array
    {
        $definition = StrategyRegistry::find($key);
        if ($definition === null) {
            return null;
        }
        $ctx ??= StrategyContext::for($workspace);
        $results = $this->results($ctx);
        $item = $this->present($definition, $ctx, $this->potential->forWorkspace($workspace), $results['strategies'], in_array($key, $this->recommended($ctx, 3), true));
        $parts = [];
        foreach ($definition->blocks as $i => $block) {
            $state = $block->state($ctx);
            $parts[] = [
                'kind' => $block->kind(),
                'key' => $block instanceof FlowBlock ? $block->libraryKey : null,
                // Lo que crea o prende, dicho para la persona («Automatización: Carrito abandonado»).
                'name' => $block instanceof FlowBlock ? __('strategies.parts.flow', ['name' => $block->libraryName()]) : __('strategies.parts.'.$block->kind()),
                'primary' => $i === 0,
                'status' => $state->status,
                'problems' => $state->problems,
                'ready' => ! $block instanceof FlowBlock || $block->ready($ctx),
                'to' => $state->to,
            ];
        }

        return $item + [
            'steps' => $this->steps($definition, $ctx),
            'preview' => $definition->primary()->preview($ctx),
            'parts' => $parts,
            'month' => $results['month'],
            'currency' => (string) ($workspace->currency ?: 'COP'),
        ];
    }

    /**
     * El orden de «Recomendadas para ti» para el negocio (todas las que su vertical recomienda, también las de
     * «Próximamente»): la lista de su vertical (`recommendedFor`) y, si es «tienda + servicios», intercalada con la de
     * la tienda. Con `vertical`, la de esa vertical.
     *
     * @return list<string>
     */
    public function rankedOrder(StrategyContext $ctx, ?string $vertical = null): array
    {
        return $ctx->remember('ranked:'.($vertical ?? '-'), function () use ($ctx, $vertical): array {
            $type = $ctx->businessType();
            if ($vertical !== null && $type !== 'both') {
                $type = Verticals::businessType($vertical);
            }
            $vertical ??= $ctx->vertical();
            $lists = [];
            if ($type === 'store' || $type === 'both') {
                $lists[] = Verticals::STORE;
            }
            if ($type === 'services' || $type === 'both') {
                $lists[] = $vertical === Verticals::STORE ? Verticals::OTHER : $vertical;
            }
            $ranked = [];
            foreach ($lists as $i => $list) {
                foreach (StrategyRegistry::rankedFor($list) as $key) {
                    $rank = StrategyRegistry::find($key)?->rankFor($list) ?? 99;
                    $ranked[$key] = min($ranked[$key] ?? PHP_INT_MAX, $rank * 10 + $i);
                }
            }
            asort($ranked);

            return array_keys($ranked);
        });
    }

    /**
     * Las recomendadas que se pueden prender ya, en el orden de rankedOrder (contraentrega o pago pendiente según si
     * hay pedidos contraentrega), sin las de «Próximamente», las que el plan no incluye ni, salvo que `includeActive`
     * (el paso 5 del asistente las muestra con su interruptor), las que ya están prendidas o en pausa.
     *
     * @return list<string>
     */
    public function recommended(StrategyContext $ctx, int $limit, bool $includeActive = false, ?string $vertical = null): array
    {
        $hasCod = ($this->potential->forWorkspace($ctx->workspace)['cod_pending'] ?? null) !== null;
        $candidates = array_values(array_diff($this->rankedOrder($ctx, $vertical), [$hasCod ? 'payment_pending' : 'cod_confirm']));
        $out = [];
        foreach ($candidates as $key) {
            $definition = StrategyRegistry::find($key);
            if ($definition === null || ($vertical === null && ! $ctx->serves($definition))) {
                continue;
            }
            $status = $this->evaluate($definition, $ctx)['status'];
            if (in_array($status, ['not_in_plan', 'coming_soon'], true) || (! $includeActive && in_array($status, ['active', 'attention', 'paused'], true))) {
                continue;
            }
            $out[] = $key;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Las siguientes que conviene prender (para el Inicio): las recomendadas primero y después las que más potencial
     * en dinero tienen, sin las activas, las pausadas ni las que no están en el plan.
     *
     * @return list<string>
     */
    public function suggestions(StrategyContext $ctx, int $limit): array
    {
        $recommended = $this->recommended($ctx, 10);
        $potentials = $this->potential->forWorkspace($ctx->workspace);
        $rest = [];
        foreach (StrategyRegistry::all() as $definition) {
            if (in_array($definition->key, $recommended, true) || ! $ctx->serves($definition)) {
                continue;
            }
            if (in_array($this->evaluate($definition, $ctx)['status'], ['available', 'needs_setup'], true)) {
                $p = $definition->potential !== null ? ($potentials[$definition->potential] ?? null) : null;
                $rest[$definition->key] = [(float) ($p['estimated'] ?? 0), (float) ($p['value'] ?? 0), (int) ($p['count'] ?? 0)];
            }
        }
        uasort($rest, fn (array $a, array $b) => $b <=> $a);

        return array_slice([...$recommended, ...array_keys($rest)], 0, $limit);
    }

    /**
     * @return array{month: array{from: string, to: string}, strategies: array<string, array<string, mixed>>, breakdown: list<array<string, mixed>>}
     */
    public function results(StrategyContext $ctx): array
    {
        $afterHours = $this->evaluate(StrategyRegistry::find('after_hours') ?? throw new \LogicException('after_hours'), $ctx)['state']->isOn();

        return $this->results->forMonth($ctx->workspace, null, $afterHours);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $potentials
     * @param  array<string, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function present(StrategyDefinition $definition, StrategyContext $ctx, array $potentials, array $results, bool $recommended = false, ?string $vertical = null): array
    {
        $eval = $this->evaluate($definition, $ctx);
        $rank = array_search($definition->key, $this->rankedOrder($ctx, $vertical), true);
        $available = $eval['status'] !== 'coming_soon';
        $row = $ctx->row($definition->key);
        $text = $this->texts($definition->key, $ctx);
        $on = in_array($eval['status'], ['active', 'attention', 'paused'], true);

        return [
            'key' => $definition->key,
            'goal' => $definition->goal,
            'audience' => $definition->audience,
            'business_types' => $definition->businessTypes(),
            'verticals' => $definition->verticals ?? 'all',
            'relevant' => $ctx->serves($definition),
            'recommended' => $recommended,
            'recommended_rank' => $rank === false ? null : $rank + 1,
            'available' => $available,
            'unavailable_reason' => $available ? null : __('strategies.coming_soon'),
            'name' => $text['name'],
            'promise' => $text['promise'],
            'status' => $eval['status'],
            'problems' => array_map(fn (string $p) => ['code' => $p, 'message' => __('strategies.problems.'.$p)], $eval['state']->problems),
            'modules' => $definition->modules,
            'plans' => $eval['missing_modules'] !== [] ? $this->plansWith($ctx, $eval['missing_modules']) : [],
            'requirements' => array_map(fn (Requirement $r) => $r->toArray(), $eval['requirements']),
            'abilities' => ['manageMarketing', ...$definition->abilities],
            'potential' => $definition->potential !== null ? ($potentials[$definition->potential] ?? null) : null,
            'result' => $on || ($results[$definition->key]['orders'] ?? 0) > 0 ? ($results[$definition->key] ?? null) : null,
            'manage_to' => $eval['state']->to,
            'activated_at' => $row?->activated_at?->toIso8601String(),
            'paused_at' => $row?->status === 'paused' ? $row->paused_at?->toIso8601String() : null,
        ];
    }

    /**
     * Nombre y promesa en la versión del negocio (la de servicios si existe y el negocio solo vende servicios).
     *
     * @return array{name: string, promise: string, steps: list<string>}
     */
    public function texts(string $key, StrategyContext $ctx): array
    {
        $base = (array) __('strategies.items.'.$key);
        $services = $ctx->speaksServices() && is_array($base['services'] ?? null) ? $base['services'] : [];
        $pick = fn (string $field) => $services[$field] ?? $base[$field] ?? null;

        return [
            'name' => (string) ($pick('name') ?? $key),
            'promise' => (string) ($pick('promise') ?? ''),
            'steps' => array_values(array_map('strval', (array) ($pick('steps') ?? []))),
        ];
    }

    /** @return list<string> «cómo funciona» en 3 pasos */
    private function steps(StrategyDefinition $definition, StrategyContext $ctx): array
    {
        return $this->texts($definition->key, $ctx)['steps'];
    }

    /**
     * Los planes públicos que incluyen esos módulos (para «Disponible en el plan…»).
     *
     * @param  list<string>  $modules
     * @return list<array{id: string, name: string}>
     */
    private function plansWith(StrategyContext $ctx, array $modules): array
    {
        $plans = $ctx->remember('plans', fn () => Plan::query()->where('public', true)->whereNull('archived_at')->orderBy('price_usd')->get(['id', 'name', 'modules']));
        $out = [];
        foreach ($plans as $plan) {
            if (array_diff($modules, (array) $plan->modules) === []) {
                $out[] = ['id' => (string) $plan->id, 'name' => (string) $plan->name];
            }
        }

        return $out;
    }
}
