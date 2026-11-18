<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Api\Http\V1\AnalyticsController;
use App\Domain\Mcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Indicadores del negocio en un periodo (ventas, pedidos, ticket promedio, sesiones, conversión, clientes nuevos y recurrentes, contraentrega pendiente) con la comparación al periodo anterior. Por mes (month=AAAA-MM) o por rango (from y to, AAAA-MM-DD); sin nada, los últimos 30 días.')]
#[IsReadOnly]
final class GetKpisTool extends Tool
{
    protected string $name = 'get_kpis';

    public function handle(Request $request, MetricQueryRunner $runner): Response
    {
        if (($denied = McpContext::deny('analytics:read')) !== null) {
            return $denied;
        }
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Response::json(AnalyticsController::kpisFor(McpContext::workspace(), $runner, $data['month'] ?? null, $data['from'] ?? null, $data['to'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('Mes AAAA-MM (p. ej. 2026-10).'),
            'from' => $schema->string()->description('Primer día AAAA-MM-DD.'),
            'to' => $schema->string()->description('Último día incluido AAAA-MM-DD.'),
        ];
    }
}
