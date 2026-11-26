<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use RuntimeException;

/** La estrategia necesita un módulo que el plan no incluye (M21): la API responde 402 `plan_required`. */
final class PlanRequired extends RuntimeException
{
    /**
     * @param  list<string>  $modules
     * @param  list<array{id: string, name: string}>  $plans  planes que sí lo incluyen
     */
    public function __construct(public readonly array $modules, public readonly array $plans)
    {
        parent::__construct(__('strategies.errors.plan_required'));
    }
}
