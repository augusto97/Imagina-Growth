<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;

/** Salida del flujo. */
final class ExitHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        return Outcome::exit('exit_node');
    }
}
