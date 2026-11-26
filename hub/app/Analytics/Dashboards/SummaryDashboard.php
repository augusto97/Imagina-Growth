<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Models\Workspace;

/**
 * El Resumen de Resultados (rediseño v2 §3, la entrada de la sección): lo que vendió el negocio en el periodo y lo que
 * generó la plataforma, por estrategia y por canal, frente al periodo de comparación. El dinero generado sale de
 * `MoneyGenerated` (cada venta una sola vez) y el desglose por estrategia de `StrategyResults`, igual que el Inicio,
 * pero con el periodo de los tableros. No usa los filtros de sitio, canal, dispositivo ni ciudad: el dinero generado
 * no se puede partir así sin contar dos veces. Un negocio sin tienda lee además `GET services/results`.
 */
final class SummaryDashboard extends Dashboard
{
    public const KPIS = ['revenue', 'orders', 'aov', 'new_customers', 'returning_customers'];

    public function __construct(
        private readonly MetricQueryRunner $runner,
        private readonly MoneyGenerated $money,
        private readonly StrategyCatalog $catalog,
    ) {}

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $ctx = StrategyContext::for($workspace);
        $none = new Filters;
        $kpis = $this->runner->run($workspace, new MetricQuery(self::KPIS, [], $period, $none));
        $series = $this->runner->run($workspace, new MetricQuery(['revenue', 'orders'], ['date'], $period, $none));
        $compare = $period->compare;

        $now = $this->money->forWorkspace($workspace, $period->from, $period->to);
        $before = $compare !== null ? $this->money->forWorkspace($workspace, $compare->from, $compare->to) : null;
        $total = (float) $now['total'];
        $previous = $before !== null ? (float) $before['total'] : null;

        return [
            'business_type' => $ctx->businessType(),
            'sales' => ['totals' => $kpis['totals'], 'compare' => $kpis['compare_totals']],
            'series' => $series['rows'],
            'generated' => [
                'total' => $total,
                'orders' => $now['orders'],
                'store_total' => (float) $now['store_total'],
                'store_orders' => $now['store_orders'],
                'share_pct' => $now['share_pct'],
                'previous' => $before === null ? null : ['total' => $previous, 'orders' => $before['orders'], 'share_pct' => $before['share_pct']],
                'change_pct' => $previous !== null && $previous > 0 ? round(($total - $previous) / $previous * 100, 1) : null,
            ],
            'by_strategy' => $this->byStrategy($ctx, $period),
            'by_channel' => $this->byChannel($workspace, $period),
        ];
    }

    /**
     * Lo que generó cada estrategia (y lo que no es una estrategia: campañas, afiliados, otras automatizaciones), con lo
     * del periodo de comparación, sus conversiones y sus mensajes.
     *
     * @return list<array{key: string, strategy: string|null, bucket: string, name: string, revenue: float, orders: int, previous: float|null,
     *     conversions: array{kind: string, count: int}|null, messages: int|null}>
     */
    private function byStrategy(StrategyContext $ctx, Period $period): array
    {
        $now = $this->catalog->resultsFor($ctx, $period->from, $period->to);
        $before = $period->compare !== null ? $this->catalog->resultsFor($ctx, $period->compare->from, $period->compare->to) : null;
        $previous = [];
        foreach ($before['breakdown'] ?? [] as $row) {
            $previous[(string) ($row['strategy'] ?? $row['bucket'])] = (float) $row['revenue'];
        }
        $rows = [];
        foreach ($now['breakdown'] as $row) {
            $key = (string) ($row['strategy'] ?? $row['bucket']);
            $strategy = $row['strategy'] !== null ? (string) $row['strategy'] : null;
            $result = $strategy !== null ? ($now['strategies'][$strategy] ?? null) : null;
            $conversions = is_array($result['conversions'] ?? null) ? $result['conversions'] : null;
            $rows[] = [
                'key' => $key,
                'strategy' => $strategy,
                'bucket' => (string) $row['bucket'],
                'name' => $strategy !== null ? (string) $this->catalog->texts($strategy, $ctx)['name'] : (string) __('home.money.buckets.'.$row['bucket']),
                'revenue' => (float) $row['revenue'],
                'orders' => (int) $row['orders'],
                'previous' => $before === null ? null : ($previous[$key] ?? 0.0),
                'conversions' => $conversions !== null && isset($conversions['kind'], $conversions['count'])
                    ? ['kind' => (string) $conversions['kind'], 'count' => (int) $conversions['count']] : null,
                'messages' => is_int($result['messages'] ?? null) ? $result['messages'] : null,
            ];
        }

        return $rows;
    }

    /** @return list<array{channel: string, revenue: float, orders: int, previous: float|null}> */
    private function byChannel(Workspace $workspace, Period $period): array
    {
        $before = [];
        if ($period->compare !== null) {
            foreach ($this->money->byChannel($workspace, $period->compare->from, $period->compare->to) as $row) {
                $before[$row['channel']] = (float) $row['revenue'];
            }
        }

        return array_map(fn (array $row) => [
            'channel' => $row['channel'],
            'revenue' => (float) $row['revenue'],
            'orders' => $row['orders'],
            'previous' => $period->compare === null ? null : ($before[$row['channel']] ?? 0.0),
        ], $this->money->byChannel($workspace, $period->from, $period->to));
    }
}
