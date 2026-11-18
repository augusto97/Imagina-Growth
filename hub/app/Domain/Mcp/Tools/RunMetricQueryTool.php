<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Analytics\Semantic\Catalog;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Intelligence\Ai\Features\AskData\MetricQueryTool;
use App\Domain\Mcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Consulta las métricas del negocio (pedidos, ventas, tráfico, clientes) por rango de fechas, con hasta 2 dimensiones y filtros por canal, dispositivo o ciudad. Usa las mismas definiciones del tablero. Devuelve totales, comparación y filas.')]
#[IsReadOnly]
final class RunMetricQueryTool extends Tool
{
    protected string $name = 'run_metric_query';

    public function handle(Request $request, MetricQueryRunner $runner): Response
    {
        if (($denied = McpContext::deny('analytics:read')) !== null) {
            return $denied;
        }
        $workspace = McpContext::workspace();
        try {
            $query = MetricQueryTool::normalize($request->all());
            $result = $runner->run($workspace, MetricQueryTool::toMetricQuery($workspace, $query));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'query' => $query,
            'currency' => $workspace->currency,
            'units' => array_combine($query['metrics'], array_map(fn (string $m) => Catalog::METRICS[$m]['unit'], $query['metrics'])),
            'totals' => $result['totals'],
            'compare_totals' => $result['compare_totals'] ?? null,
            'rows' => array_slice($result['rows'], 0, 100),
            'row_count' => count($result['rows']),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'metrics' => $schema->array()->items($schema->string()->enum(array_keys(Catalog::METRICS)))->min(1)->max(4)->description('De 1 a 4 métricas del catálogo.')->required(),
            'dimensions' => $schema->array()->items($schema->string()->enum(array_keys(Catalog::DIMENSIONS)))->max(2)->description('Hasta 2 dimensiones; vacío para solo totales.'),
            'from' => $schema->string()->description('Primer día AAAA-MM-DD (zona del negocio).')->required(),
            'to' => $schema->string()->description('Último día incluido AAAA-MM-DD.')->required(),
            'compare' => $schema->string()->enum(['previous', 'year', 'none'])->description('Comparar con el periodo anterior, el mismo del año pasado o nada.'),
            'granularity' => $schema->string()->enum(['day', 'week', 'month', 'auto'])->description('Agrupación de la dimensión fecha.'),
            'channel' => $schema->string()->description('Filtrar por canal (paid_social, organic_search, email, whatsapp…).'),
            'device' => $schema->string()->enum(['mobile', 'tablet', 'desktop']),
            'city' => $schema->string()->description('Filtrar por ciudad.'),
            'limit' => $schema->integer()->description('Máximo de filas (1 a 100).'),
        ];
    }
}
