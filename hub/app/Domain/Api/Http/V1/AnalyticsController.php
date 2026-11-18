<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Analytics\Dashboards\OverviewDashboard;
use App\Analytics\Semantic\Catalog;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Api\Support\ApiError;
use App\Domain\Intelligence\Ai\Features\AskData\MetricQueryTool;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Métricas de la capa semántica (hub/CLAUDE.md §10): las mismas definiciones del tablero.
 */
#[Group('Analítica', weight: 7)]
final class AnalyticsController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly MetricQueryRunner $runner) {}

    /**
     * Catálogo de métricas y dimensiones
     *
     * Los nombres que acepta `analytics/query`, con su etiqueta, unidad y definición.
     * Requiere `analytics:read`.
     */
    public function catalog(): JsonResponse
    {
        return new JsonResponse([
            'metrics' => array_map(fn (string $k, array $m) => ['key' => $k, 'label' => $m['label'], 'unit' => $m['unit'], 'definition' => $m['definition']], array_keys(Catalog::METRICS), Catalog::METRICS),
            'dimensions' => array_map(fn (string $k, array $d) => ['key' => $k, 'label' => $d['label']], array_keys(Catalog::DIMENSIONS), Catalog::DIMENSIONS),
        ]);
    }

    /**
     * Consultar métricas
     *
     * `metrics` (1 a 4), `dimensions` (hasta 2), `from` y `to` (AAAA-MM-DD, en la zona del negocio),
     * y opcionales `compare` (previous, year, none), `granularity`, `channel`, `device`, `city` y
     * `limit`. Requiere `analytics:read`.
     */
    public function query(Request $request): JsonResponse
    {
        try {
            $query = MetricQueryTool::normalize($request->all());
            $result = $this->runner->run($this->current->get(), MetricQueryTool::toMetricQuery($this->current->get(), $query));
        } catch (InvalidArgumentException $e) {
            return ApiError::make(422, 'invalid_query', $e->getMessage());
        }

        return new JsonResponse([
            'query' => $query,
            'currency' => $this->current->get()->currency,
            'units' => array_combine($query['metrics'], array_map(fn (string $m) => Catalog::METRICS[$m]['unit'], $query['metrics'])),
            'totals' => $result['totals'],
            'compare_totals' => $result['compare_totals'] ?? null,
            'rows' => $result['rows'],
        ]);
    }

    /**
     * Indicadores del periodo
     *
     * Los KPI del tablero (ventas, pedidos, ticket promedio, sesiones, conversión, clientes nuevos y
     * recurrentes, contraentrega pendiente) con la comparación al periodo anterior. Por mes con
     * `month=AAAA-MM` (para el reporte mensual de Imagina Reports) o por rango con `from` y `to`;
     * sin nada, los últimos 30 días. Requiere `analytics:read`.
     */
    public function kpis(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        return new JsonResponse(self::kpisFor($this->current->get(), $this->runner, $request->query('month'), $request->query('from'), $request->query('to')));
    }

    /**
     * Compartido con la herramienta `get_kpis` del servidor MCP.
     *
     * @return array<string, mixed>
     */
    public static function kpisFor(Workspace $workspace, MetricQueryRunner $runner, mixed $month, mixed $from, mixed $to): array
    {
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $start = CarbonImmutable::parse($month.'-01', $tz);
            $from = $start->format('Y-m-d');
            $to = $start->endOfMonth()->format('Y-m-d');
        }
        $period = Period::make($tz, is_string($from) ? $from : null, is_string($to) ? $to : null, 'previous');
        $result = $runner->run($workspace, new MetricQuery(OverviewDashboard::KPIS, [], $period));

        return [
            'period' => ['from' => $period->from->format('Y-m-d'), 'to' => $period->to->subDay()->format('Y-m-d'), 'timezone' => $tz],
            'currency' => $workspace->currency,
            'kpis' => $result['totals'],
            'compare' => $result['compare_totals'] ?? null,
            'units' => array_combine(OverviewDashboard::KPIS, array_map(fn (string $m) => Catalog::METRICS[$m]['unit'], OverviewDashboard::KPIS)),
        ];
    }
}
