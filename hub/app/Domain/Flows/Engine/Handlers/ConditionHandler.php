<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Filters;
use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;

/**
 * Condición por atributo, comportamiento o predicción (el mismo AST de los
 * segmentos, evaluado solo para el contacto) o sobre los datos del disparador
 * (`mode: context`, p. ej. la respuesta por botón). Sale por `yes` o `no`.
 */
final class ConditionHandler implements NodeHandler
{
    public function __construct(private readonly SegmentCompiler $compiler) {}

    public function handle(RunState $state, array $node): Outcome
    {
        $config = $node['config'];
        if (($config['mode'] ?? 'segment') === 'context') {
            $data = (array) ($state->run->context ?? []);
            $ok = Filters::matches([['prop' => $config['path'] ?? '', 'operator' => $config['operator'] ?? 'eq', 'value' => $config['value'] ?? null]], $data);

            return Outcome::next($ok ? 'yes' : 'no', details: ['result' => $ok]);
        }
        $ctx = CompileContext::forContact($state->workspace, $state->run->contact_id);
        try {
            $ok = $this->compiler->query((array) ($config['definition'] ?? []), $ctx)->exists();
        } finally {
            $ctx->cleanup();
        }

        return Outcome::next($ok ? 'yes' : 'no', details: ['result' => $ok]);
    }
}
