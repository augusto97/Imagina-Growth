<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;

/**
 * División A/B aleatoria por pesos. El sorteo sale de un hash del run y el nodo:
 * un reintento cae siempre en la misma variante.
 */
final class AbSplitHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        $variants = array_values(array_filter((array) ($node['config']['variants'] ?? []), 'is_array'));
        $draw = hexdec(substr(hash('sha256', $state->run->id.':'.$node['id'].':'.$state->attempt), 0, 8)) % 100;
        $acc = 0;
        $chosen = 'a';
        foreach ($variants as $variant) {
            $chosen = (string) ($variant['key'] ?? 'a');
            $acc += (int) ($variant['weight'] ?? 0);
            if ($draw < $acc) {
                break;
            }
        }

        return Outcome::next($chosen, details: ['variant' => $chosen]);
    }
}
