<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine;

/** Ejecuta un tipo de nodo para un run (hub/CLAUDE.md §7). */
interface NodeHandler
{
    /**
     * @param  array{id: string, type: string, config: array<string, mixed>}  $node
     */
    public function handle(RunState $state, array $node): Outcome;
}
