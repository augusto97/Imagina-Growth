<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\AskData;

use App\Analytics\Semantic\Catalog;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Workspaces\Models\Workspace;
use InvalidArgumentException;

/**
 * La única herramienta de «Pregúntale a tus datos» (inteligencia.md §6.3,
 * hub/CLAUDE.md §12): `run_metric_query`. El modelo arma una consulta estructurada
 * sobre la capa semántica (métricas y dimensiones del catálogo, rango, comparación,
 * granularidad y filtros); el hub la valida y la corre con `MetricQueryRunner`
 * en el espacio de trabajo activo. El modelo nunca escribe SQL.
 */
final class MetricQueryTool
{
    public const NAME = 'run_metric_query';

    public const MAX_ROWS_FOR_MODEL = 40;

    /** @var array<string, mixed>|null la última consulta válida (normalizada) */
    public ?array $lastQuery = null;

    /** @var array{rows: list<array<string, mixed>>, totals: array<string, float|int>, compare_totals: array<string, float|int>|null}|null */
    public ?array $lastResult = null;

    public function __construct(private readonly Workspace $workspace, private readonly MetricQueryRunner $runner) {}

    public function tool(): AiTool
    {
        return new AiTool(self::NAME, 'Consulta las métricas del negocio (pedidos, ventas, tráfico) por rango de fechas, con dimensiones y filtros opcionales. Devuelve los totales, la comparación y las filas por dimensión.', self::schema(), fn (array $input): array => $this->run($input));
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullable = fn (array $s) => ['anyOf' => [$s, ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'metrics' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Catalog::METRICS)], 'description' => 'De 1 a 4 métricas del catálogo.'],
                'dimensions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Catalog::DIMENSIONS)], 'description' => 'Hasta 2 dimensiones; vacío para solo totales.'],
                'from' => ['type' => 'string', 'description' => 'Primer día, AAAA-MM-DD (en la zona del negocio).'],
                'to' => ['type' => 'string', 'description' => 'Último día incluido, AAAA-MM-DD.'],
                'compare' => ['type' => 'string', 'enum' => ['previous', 'year', 'none'], 'description' => 'Comparar con el periodo anterior, el mismo del año pasado o nada.'],
                'granularity' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'auto'], 'description' => 'Agrupación de la dimensión fecha.'],
                'channel' => $nullable(['type' => 'string', 'description' => 'Filtrar por canal (p. ej. paid_social, organic_search, email, whatsapp).']),
                'device' => $nullable(['type' => 'string', 'enum' => ['mobile', 'tablet', 'desktop']]),
                'city' => $nullable(['type' => 'string', 'description' => 'Filtrar por ciudad.']),
                'limit' => ['type' => 'integer', 'description' => 'Máximo de filas (1 a 100).'],
            ],
            'required' => ['metrics', 'dimensions', 'from', 'to', 'compare', 'granularity', 'channel', 'device', 'city', 'limit'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> los hechos que ve el modelo
     */
    public function run(array $input): array
    {
        $query = self::normalize($input);
        $metricQuery = self::toMetricQuery($this->workspace, $query);
        $result = $this->runner->run($this->workspace, $metricQuery);
        $this->lastQuery = $query;
        $this->lastResult = $result;

        return [
            'periodo' => ['desde' => $query['from'], 'hasta' => $query['to'], 'comparacion' => $query['compare']],
            'moneda' => (string) $this->workspace->currency,
            'unidades' => array_map(fn (string $m) => Catalog::METRICS[$m]['unit'], array_combine($query['metrics'], $query['metrics'])),
            'totales' => $result['totals'],
            'totales_comparacion' => $result['compare_totals'],
            'filas' => array_slice($result['rows'], 0, self::MAX_ROWS_FOR_MODEL),
            'filas_totales' => count($result['rows']),
        ];
    }

    /**
     * Valida la entrada del modelo y la deja lista para guardar y repetir.
     *
     * @param  array<string, mixed>  $input
     * @return array{metrics: list<string>, dimensions: list<string>, from: string, to: string, compare: string, granularity: string, channel: string|null, device: string|null, city: string|null, limit: int}
     */
    public static function normalize(array $input): array
    {
        $metrics = array_values(array_unique(array_map('strval', (array) ($input['metrics'] ?? []))));
        $dimensions = array_values(array_unique(array_map('strval', (array) ($input['dimensions'] ?? []))));
        if ($metrics === [] || count($metrics) > 4) {
            throw new InvalidArgumentException('Pide entre 1 y 4 métricas del catálogo.');
        }
        foreach ($metrics as $m) {
            if (! Catalog::has($m)) {
                throw new InvalidArgumentException("Métrica desconocida: {$m}. Usa solo las del catálogo.");
            }
        }
        foreach ($dimensions as $d) {
            if (! isset(Catalog::DIMENSIONS[$d])) {
                throw new InvalidArgumentException("Dimensión desconocida: {$d}.");
            }
        }
        if (count($dimensions) > 2) {
            throw new InvalidArgumentException('Máximo dos dimensiones.');
        }
        $date = fn (mixed $v): string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v
            : throw new InvalidArgumentException('Las fechas van en formato AAAA-MM-DD.');
        $str = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 120) : null;
        $device = $str($input['device'] ?? null);

        return [
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'from' => $date($input['from'] ?? null),
            'to' => $date($input['to'] ?? null),
            'compare' => in_array($input['compare'] ?? null, ['previous', 'year', 'none'], true) ? (string) $input['compare'] : 'previous',
            'granularity' => in_array($input['granularity'] ?? null, ['day', 'week', 'month'], true) ? (string) $input['granularity'] : 'auto',
            'channel' => $str($input['channel'] ?? null),
            'device' => in_array($device, ['mobile', 'tablet', 'desktop'], true) ? $device : null,
            'city' => $str($input['city'] ?? null),
            'limit' => max(1, min(100, (int) ($input['limit'] ?? 50))),
        ];
    }

    /**
     * @param  array{metrics: list<string>, dimensions: list<string>, from: string, to: string, compare: string, granularity: string, channel: string|null, device: string|null, city: string|null, limit: int}  $query
     */
    public static function toMetricQuery(Workspace $workspace, array $query): MetricQuery
    {
        $period = Period::make((string) $workspace->timezone, $query['from'], $query['to'], $query['compare'], $query['granularity'] === 'auto' ? null : $query['granularity']);

        return new MetricQuery($query['metrics'], $query['dimensions'], $period,
            new Filters(channel: $query['channel'], device: $query['device'], city: $query['city']), $query['limit']);
    }
}
