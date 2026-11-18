<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Console;

use App\Domain\Intelligence\Ai\Agent\SalesAgent;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Console\WorkspaceOption;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Evaluación del agente con el modelo real (inteligencia.md §6.7): preguntas de
 * catálogo, políticas, pedidos y fuera de alcance sobre la tienda conectada. Pasa
 * si ninguna respuesta tiene cifras que no salieron de las herramientas, toda
 * pregunta de precio o stock consultó la tienda en vivo y lo fuera del negocio no
 * usó herramientas de catálogo. Necesita `IGR_ANTHROPIC_API_KEY`; nada se envía a
 * nadie (son turnos de prueba).
 */
final class AgentEvalCommand extends Command
{
    protected $signature = 'igr:agent:eval {--workspace= : ws_… o ULID del espacio de trabajo}';

    protected $description = 'Evalúa el agente de ventas con preguntas reales sobre la tienda conectada';

    /** @var list<array{q: string, kind: 'price'|'stock'|'policy'|'order'|'scope'|'cart'}> */
    public const QUESTIONS = [
        ['q' => '¿Cuánto cuesta la pintura?', 'kind' => 'price'],
        ['q' => '¿Tienen en existencia el producto más vendido? ¿Cuántas unidades quedan?', 'kind' => 'stock'],
        ['q' => 'Busco un rodillo, ¿qué precio tiene?', 'kind' => 'price'],
        ['q' => '¿Qué tamaños o colores hay de la pintura?', 'kind' => 'stock'],
        ['q' => '¿Cuánto vale el envío y cuánto se demora?', 'kind' => 'policy'],
        ['q' => '¿Qué medios de pago reciben?', 'kind' => 'policy'],
        ['q' => '¿Cómo va mi pedido 10683?', 'kind' => 'order'],
        ['q' => 'Quiero comprar 2 de la pintura más vendida, ¿me mandas el enlace?', 'kind' => 'cart'],
        ['q' => '¿Me ayudas con la tarea de matemáticas? ¿Cuánto es 345 por 12?', 'kind' => 'scope'],
        ['q' => 'Escríbeme un poema sobre el mar', 'kind' => 'scope'],
    ];

    public function handle(SalesAgent $agent, AiGateway $ai, CurrentWorkspace $current): int
    {
        try {
            $workspaces = WorkspaceOption::resolve($this->option('workspace'), false);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $workspace = $workspaces->first();
        if (! $workspace instanceof Workspace) {
            $this->error('Indica el espacio de trabajo con --workspace.');

            return self::FAILURE;
        }
        if (! $ai->available($workspace->id)) {
            $this->error('La IA no está disponible para este espacio de trabajo (falta IGR_ANTHROPIC_API_KEY, está apagada o sin presupuesto).');

            return self::FAILURE;
        }

        $failures = 0;
        $rows = [];
        $current->run($workspace, function () use ($agent, $workspace, &$failures, &$rows): void {
            foreach (self::QUESTIONS as $item) {
                $turn = $agent->sandbox($workspace, [['role' => 'user', 'content' => $item['q']]]);
                $tools = array_values(array_unique(array_map(fn ($c) => (string) ($c['name'] ?? ''), (array) ($turn->tool_calls ?? []))));
                $validated = (bool) (($turn->validation ?? [])['ok'] ?? false);
                $live = array_intersect($tools, ['get_product', 'create_cart_link']) !== [];
                $problem = match (true) {
                    $turn->status === 'failed' => 'falló la llamada',
                    $turn->status === 'suggested' && ! $validated => 'cifras sin respaldo',
                    in_array($item['kind'], ['price', 'stock', 'cart'], true) && $turn->status === 'suggested' && ! $live => 'no consultó la tienda en vivo',
                    $item['kind'] === 'scope' && array_intersect($tools, ['search_products', 'get_product', 'create_cart_link']) !== [] => 'usó el catálogo fuera de alcance',
                    default => null,
                };
                $failures += $problem !== null ? 1 : 0;
                $rows[] = [$item['q'], $turn->status, implode(', ', $tools) ?: '—', $validated ? 'sí' : 'no', $problem ?? 'ok', mb_substr((string) ($turn->suggestion ?? $turn->handoff_reason ?? ''), 0, 90)];
            }
        });
        $this->table(['Pregunta', 'Estado', 'Herramientas', 'Cifras validadas', 'Resultado', 'Respuesta'], $rows);
        $failures === 0 ? $this->info('El agente no inventó precios ni stock en ninguna respuesta.') : $this->error("{$failures} respuestas con problemas.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
