<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Analyst;

use App\Analytics\Semantic\Catalog;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Reports\WeeklyReport;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * Hechos del analista semanal (inteligencia.md §6.2): los KPI de la semana frente a
 * la anterior y frente al promedio de las 4 semanas previas, más los 5 hallazgos
 * principales. Los cambios llegan ya calculados (el modelo nunca calcula) y sin
 * datos personales.
 */
final class WeeklyFacts
{
    private const TOP_INSIGHTS = 'App\\Domain\\Intelligence\\Insights\\Queries\\TopInsights';

    public function __construct(
        private readonly WeeklyReport $report,
        private readonly MetricQueryRunner $runner,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  array<string, mixed>|null  $base  los hechos del resumen semanal, si ya se calcularon
     * @return array<string, mixed>
     */
    public function build(Workspace $workspace, CarbonImmutable $weekStart, ?array $base = null): array
    {
        $base ??= $this->report->facts($workspace, $weekStart);

        return $this->current->run($workspace, function () use ($workspace, $weekStart, $base): array {
            $avgPeriod = Period::make($workspace->timezone, $weekStart->subWeeks(4)->toDateString(), $weekStart->subDay()->toDateString(), 'none', 'week');
            $avg = $this->runner->run($workspace, new MetricQuery(WeeklyReport::METRICS, [], $avgPeriod, new Filters))['totals'];

            $kpis = [];
            foreach ((array) $base['numbers'] as $n) {
                $metric = (string) $n['metric'];
                $unit = Catalog::METRICS[$metric]['unit'] ?? 'count';
                $value = (float) $n['value'];
                $previous = (float) $n['previous'];
                // Las métricas de suma se promedian por semana; las razones (ticket, conversión) ya son del periodo.
                $average = in_array($metric, ['aov', 'conversion_rate'], true) ? (float) ($avg[$metric] ?? 0) : (float) ($avg[$metric] ?? 0) / 4;
                $kpis[] = [
                    'metrica' => $metric,
                    'nombre' => Catalog::METRICS[$metric]['label'] ?? $metric,
                    'unidad' => $unit,
                    'semana' => self::round($value, $unit),
                    'semana_anterior' => self::round($previous, $unit),
                    'cambio_vs_anterior_pct' => self::change($value, $previous),
                    'promedio_4_semanas' => self::round($average, $unit),
                    'cambio_vs_promedio_pct' => self::change($value, $average),
                ];
            }

            return [
                'negocio' => (string) $workspace->name,
                'moneda' => (string) $workspace->currency,
                'semana' => ['desde' => $weekStart->toDateString(), 'hasta' => $weekStart->addDays(6)->toDateString()],
                'indicadores' => $kpis,
                'hallazgos' => PiiScrubber::scrub($this->insights($workspace)),
            ];
        });
    }

    /**
     * Los 5 hallazgos principales. Usa la consulta del motor de hallazgos si existe
     * (`Insights\Queries\TopInsights`) y si no, los abiertos por severidad e impacto.
     *
     * @return list<array<string, mixed>>
     */
    private function insights(Workspace $workspace): array
    {
        if (class_exists(self::TOP_INSIGHTS)) {
            $facts = app(self::TOP_INSIGHTS)->facts($workspace, 5);
            if (is_array($facts)) {
                return array_values($facts);
            }
        }

        return array_values(Insight::query()->whereIn('status', ['new', 'seen'])->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
            ->take(5)->values()
            ->map(fn (Insight $i) => array_filter([
                'titulo' => $i->title,
                'severidad' => $i->severity,
                'impacto_estimado' => $i->estimated_impact_ws !== null ? (float) $i->estimated_impact_ws : null,
                'supuestos' => $i->impact_assumptions,
                'datos' => $i->facts,
                'accion' => $i->recommended_action,
            ], fn ($v) => $v !== null))->all());
    }

    private static function round(float $value, string $unit): float
    {
        return match ($unit) {
            'percent' => round($value, 5),
            'money' => round($value),
            default => round($value, 1),
        };
    }

    private static function change(float $value, float $base): ?float
    {
        return $base == 0.0 ? null : round(($value - $base) / $base * 100, 1);
    }
}
