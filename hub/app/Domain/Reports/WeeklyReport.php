<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * Hechos del resumen semanal al dueño (modulos.md M05): 5 cifras, 3 hallazgos
 * y 1 acción de la última semana completa (lunes a domingo, zona del espacio
 * de trabajo). Sin IA hasta la Fase 4: plantilla con los hechos ya calculados.
 */
final class WeeklyReport
{
    public const METRICS = ['revenue', 'orders', 'aov', 'new_customers', 'conversion_rate'];

    public function __construct(private readonly MetricQueryRunner $runner, private readonly CurrentWorkspace $current) {}

    /** Inicio (lunes) de la última semana completa. */
    public static function lastWeekStart(Workspace $workspace, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now($workspace->timezone ?: 'UTC');

        return $now->setTimezone($workspace->timezone ?: 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
    }

    /** @return array<string, mixed> */
    public function facts(Workspace $workspace, CarbonImmutable $weekStart): array
    {
        return $this->current->run($workspace, function () use ($workspace, $weekStart): array {
            $period = Period::make($workspace->timezone, $weekStart->toDateString(), $weekStart->addDays(6)->toDateString(), 'previous', 'day');
            $result = $this->runner->run($workspace, new MetricQuery(self::METRICS, [], $period, new Filters));

            $numbers = [];
            foreach (self::METRICS as $m) {
                $value = (float) $result['totals'][$m];
                $prev = (float) ($result['compare_totals'][$m] ?? 0);
                $numbers[] = ['metric' => $m, 'value' => $value, 'previous' => $prev, 'change' => $prev == 0.0 ? null : round(($value - $prev) / $prev, 4)];
            }

            $insights = Insight::query()->whereIn('status', ['new', 'seen'])->get()
                ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
                ->take(3)->values();
            $top = $insights->first();
            $action = $top?->recommended_action ?? ['label' => __('Revisa tus clientes en riesgo y prepara una campaña de recuperación'), 'to' => 'analitica/clientes'];

            return [
                'workspace' => ['id' => $workspace->publicId(), 'name' => $workspace->name, 'currency' => $workspace->currency, 'timezone' => $workspace->timezone],
                'period' => $period->toArray(),
                'numbers' => $numbers,
                'insights' => $insights->map(fn (Insight $i) => [
                    'id' => $i->publicId(), 'severity' => $i->severity, 'title' => $i->title, 'impact' => $i->estimated_impact_ws,
                    'action' => $i->recommended_action,
                ])->all(),
                'action' => $action,
            ];
        });
    }
}
