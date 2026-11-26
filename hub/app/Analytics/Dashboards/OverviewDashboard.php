<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;

/** 1. Inicio (M05): ventas, pedidos, ticket, sesiones, conversión, nuevos frente a recurrentes y los 3 hallazgos principales. */
final class OverviewDashboard extends Dashboard
{
    public const KPIS = ['revenue', 'orders', 'aov', 'sessions', 'conversion_rate', 'new_customers', 'returning_customers', 'cod_pending_orders', 'cod_pending_revenue', 'revenue_per_visitor'];

    public function __construct(private readonly MetricQueryRunner $runner, private readonly MoneyGenerated $money) {}

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $kpis = $this->runner->run($workspace, new MetricQuery(self::KPIS, [], $period, $filters));
        $series = $this->runner->run($workspace, new MetricQuery(['revenue', 'orders', 'sessions'], ['date'], $period, $filters));

        return [
            'kpis' => $kpis['totals'],
            'compare' => $kpis['compare_totals'],
            'series' => $series['rows'],
            'attributed' => $this->attributed($workspace, $period),
            'insights' => self::topInsights(),
        ];
    }

    /**
     * El dinero generado del periodo (CLAUDE.md §3.7), con la misma regla que el panel de agencia y el reporte
     * mensual: cada venta cuenta una sola vez, en el primer módulo que la explica.
     *
     * @return array{revenue: float, orders: int, share_pct: float, available: bool, modules: list<array{key: string, revenue: float, orders: int}>}
     */
    private function attributed(Workspace $workspace, Period $period): array
    {
        $m = $this->money->forWorkspace($workspace, $period->from, $period->to);
        $modules = [];
        foreach ($m['modules'] as $module) {
            if ($module['orders'] > 0) {
                $modules[] = ['key' => $module['key'], 'revenue' => (float) $module['revenue'], 'orders' => $module['orders']];
            }
        }

        return [
            'revenue' => (float) $m['total'],
            'orders' => $m['orders'],
            'share_pct' => $m['share_pct'],
            'available' => true,
            'modules' => array_slice($modules, 0, 3),
        ];
    }

    /**
     * Los 3 hallazgos abiertos más graves. El controlador los vuelve a pedir
     * fuera de la caché del tablero: descartar uno se ve al instante.
     *
     * @return list<array{id: string, severity: string, title: string, impact: string|null, detector: string}>
     */
    public static function topInsights(): array
    {
        Insight::wakeSnoozed();

        return array_values(Insight::query()->whereIn('status', ['new', 'seen'])->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
            ->take(3)
            ->map(fn (Insight $i) => ['id' => $i->publicId(), 'severity' => (string) $i->severity, 'title' => (string) $i->title, 'impact' => $i->estimated_impact_ws === null ? null : (string) $i->estimated_impact_ws, 'detector' => (string) $i->detector_key])
            ->all());
    }
}
