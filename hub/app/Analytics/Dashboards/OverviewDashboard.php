<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;

/** 1. Inicio (M05): ventas, pedidos, ticket, sesiones, conversión, nuevos frente a recurrentes y los 3 hallazgos principales. */
final class OverviewDashboard extends Dashboard
{
    public const KPIS = ['revenue', 'orders', 'aov', 'sessions', 'conversion_rate', 'new_customers', 'returning_customers', 'cod_pending_orders', 'cod_pending_revenue', 'revenue_per_visitor'];

    public function __construct(private readonly MetricQueryRunner $runner) {}

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $kpis = $this->runner->run($workspace, new MetricQuery(self::KPIS, [], $period, $filters));
        $series = $this->runner->run($workspace, new MetricQuery(['revenue', 'orders', 'sessions'], ['date'], $period, $filters));

        $insights = Insight::query()->whereIn('status', ['new', 'seen'])->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
            ->take(3)
            ->map(fn (Insight $i) => ['id' => $i->publicId(), 'severity' => $i->severity, 'title' => $i->title, 'impact' => $i->estimated_impact_ws, 'detector' => $i->detector_key])
            ->values();

        return [
            'kpis' => $kpis['totals'],
            'compare' => $kpis['compare_totals'],
            'series' => $series['rows'],
            // Ingresos atribuidos a mensajes, widgets y referidos: llegan con las fases 2 y 3.
            'attributed' => ['revenue' => 0, 'available' => false, 'phase' => 2],
            'insights' => $insights,
        ];
    }
}
