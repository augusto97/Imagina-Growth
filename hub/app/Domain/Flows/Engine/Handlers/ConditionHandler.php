<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Durations;
use App\Domain\Flows\Graph\Filters;
use App\Domain\Flows\Support\FlowDates;
use App\Domain\Segments\Compiler\CompileContext;
use App\Domain\Segments\Compiler\SegmentCompiler;

/**
 * Condición por atributo, comportamiento o predicción (el mismo AST de los
 * segmentos, evaluado solo para el contacto), sobre los datos del disparador
 * (`mode: context`, p. ej. la respuesta por botón) o sobre cuánto falta para
 * una fecha (`mode: date`: «¿faltan más de 48 horas para la cita?», FlowDates;
 * sin fecha o con la cita inactiva, `no`). Sale por `yes` o `no`.
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

            return $this->outcome($ok, $config);
        }
        if (($config['mode'] ?? 'segment') === 'date') {
            $anchor = FlowDates::anchor($state, $config);
            $ok = $anchor['state'] === 'ok' && $anchor['at'] !== null
                && $anchor['at']->getTimestamp() - $state->now->getTimestamp() > (Durations::seconds($config['offset'] ?? null) ?? 0);

            return $this->outcome($ok, $config);
        }
        $ctx = CompileContext::forContact($state->workspace, $state->run->contact_id);
        try {
            $ok = $this->compiler->query((array) ($config['definition'] ?? []), $ctx)->exists();
        } finally {
            $ctx->cleanup();
        }

        return $this->outcome($ok, $config);
    }

    /**
     * Con `exit_if_no` la condición es un filtro de entrada: si no se cumple el run
     * sale con motivo `filter`, que no cuenta para la reentrada.
     *
     * @param  array<string, mixed>  $config
     */
    private function outcome(bool $ok, array $config): Outcome
    {
        if (! $ok && ! empty($config['exit_if_no'])) {
            return Outcome::exit('filter');
        }

        return Outcome::next($ok ? 'yes' : 'no', details: ['result' => $ok]);
    }
}
