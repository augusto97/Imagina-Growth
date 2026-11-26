<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use App\Domain\Strategies\Blocks\FlowBlock;
use App\Domain\Strategies\Blocks\StrategyBlock;
use App\Domain\Strategies\Blocks\WidgetBlock;

/**
 * Una estrategia del catálogo (docs/diseno/rediseno-v2.md §6), declarada en StrategyRegistry. Los textos (nombre,
 * promesa, cómo funciona) van en `lang/es/strategies.php` → `items.<key>`, con `services` si cambian para un negocio
 * de servicios.
 */
final class StrategyDefinition
{
    /**
     * @param  string  $goal  objetivo (StrategyRegistry::GOALS)
     * @param  string  $audience  `store`, `services` o `both`
     * @param  list<string>  $modules  módulos del plan que necesita (vacío = todo plan)
     * @param  list<string>  $requires  requisitos comunes (Requirements::KEYS)
     * @param  list<StrategyBlock>  $blocks  el primero es el principal; los demás la apoyan (no la bloquean)
     * @param  string|null  $potential  cálculo del potencial (StrategyPotential)
     * @param  list<string>  $abilities  permisos además de `manageMarketing` para activarla o pausarla
     * @param  list<string>  $exclusive  estrategias que usan el mismo bloque con otra configuración
     * @param  list<string>  $money  de qué fuentes de MoneyGenerated sale su dinero (además de sus flujos y widgets)
     * @param  list<string>|null  $verticals  verticales a las que sirve (null = todas las de sus tipos de negocio)
     * @param  array<string, int>  $recommendedFor  vertical → lugar en «Recomendadas para ti» (1 = la primera)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $goal,
        public readonly string $audience,
        public readonly array $modules,
        public readonly array $requires,
        public readonly array $blocks,
        public readonly ?string $potential = null,
        public readonly array $abilities = [],
        public readonly array $exclusive = [],
        public readonly array $money = [],
        public readonly ?array $verticals = null,
        public readonly array $recommendedFor = [],
    ) {}

    /** @return list<string> los tipos de negocio a los que sirve: `store`, `services` o los dos */
    public function businessTypes(): array
    {
        return match ($this->audience) {
            'store' => ['store'],
            'services' => ['services'],
            default => ['store', 'services'],
        };
    }

    /** Su lugar en «Recomendadas para ti» para esa vertical, o null si no se recomienda ahí. */
    public function rankFor(string $vertical): ?int
    {
        return $this->recommendedFor[$vertical] ?? null;
    }

    public function primary(): StrategyBlock
    {
        return $this->blocks[0];
    }

    /** @return list<string> claves de los flujos de la biblioteca que la forman */
    public function flowKeys(): array
    {
        $keys = [];
        foreach ($this->blocks as $block) {
            if ($block instanceof FlowBlock) {
                $keys[] = $block->libraryKey;
            }
        }

        return $keys;
    }

    /** @return list<string> tipos de widget que la forman */
    public function widgetKinds(): array
    {
        $kinds = [];
        foreach ($this->blocks as $block) {
            if ($block instanceof WidgetBlock) {
                $kinds = [...$kinds, ...$block->kinds];
            }
        }

        return $kinds;
    }
}
