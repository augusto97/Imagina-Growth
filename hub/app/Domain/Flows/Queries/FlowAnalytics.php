<?php

declare(strict_types=1);

namespace App\Domain\Flows\Queries;

use App\Domain\Flows\Models\Flow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Analítica de un flujo (modulos.md M09, «Analítica por nodo»): cuántos entran
 * y salen, conversiones (meta alcanzada), ingresos (de la meta y atribuidos por
 * clic en un mensaje del flujo) e incremento frente al grupo de control con su
 * intervalo de confianza del 95%. Sin las pruebas.
 */
final class FlowAnalytics
{
    /** @return array<string, mixed> */
    public function handle(Flow $flow, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $runs = $this->runs($flow, $from, $to);
        $groups = (clone $runs)->selectRaw("
                is_holdout,
                COUNT(*) AS entered,
                SUM(status IN ('active', 'waiting')) AS live,
                SUM(exit_reason = 'goal_reached') AS converted,
                SUM(status IN ('completed', 'exited', 'failed') AND COALESCE(exit_reason, '') NOT IN ('goal_reached', 'filter')) AS finished,
                SUM(exit_reason = 'filter') AS filtered,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(context, '$._goal.revenue_ws')) AS DECIMAL(16,2))), 0) AS revenue
            ")->groupBy('is_holdout')->get()->keyBy(fn ($r) => (int) $r->is_holdout ? 'holdout' : 'treatment');
        $treatment = $this->group($groups->get('treatment'));
        $holdout = $this->group($groups->get('holdout'));

        $attributed = DB::table('orders')->where('workspace_id', $flow->workspace_id)->where('attributed_flow_id', $flow->id)->where('is_paid', true)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<', $to))
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total_ws), 0) AS revenue')->first();

        return [
            'summary' => [
                'entered' => $treatment['entered'] + $holdout['entered'],
                'live' => $treatment['live'] + $holdout['live'],
                'converted' => $treatment['converted'],
                'conversion_rate' => $treatment['rate'],
                'goal_revenue' => $treatment['revenue'],
                'attributed_orders' => (int) ($attributed->orders ?? 0),
                'attributed_revenue' => number_format((float) ($attributed->revenue ?? 0), 2, '.', ''),
            ],
            'treatment' => $treatment,
            'holdout' => $holdout,
            'uplift' => $this->uplift($treatment, $holdout),
            'nodes' => $this->nodes($flow, $from, $to),
        ];
    }

    private function runs(Flow $flow, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return DB::table('flow_runs')->where('workspace_id', $flow->workspace_id)->where('flow_id', $flow->id)
            ->where(fn ($q) => $q->whereNull('trigger_key')->orWhere('trigger_key', 'not like', 'test:%'))
            ->when($from !== null, fn ($q) => $q->where('started_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('started_at', '<', $to));
    }

    /** @return array{entered: int, live: int, converted: int, finished: int, filtered: int, rate: float|null, revenue: string, revenue_per_contact: string|null} */
    private function group(?object $row): array
    {
        $entered = (int) ($row->entered ?? 0);
        $filtered = (int) ($row->filtered ?? 0);
        $eligible = $entered - $filtered;
        $converted = (int) ($row->converted ?? 0);
        $revenue = (float) ($row->revenue ?? 0);

        return [
            'entered' => $entered,
            'live' => (int) ($row->live ?? 0),
            'converted' => $converted,
            'finished' => (int) ($row->finished ?? 0),
            'filtered' => $filtered,
            'rate' => $eligible > 0 ? round($converted / $eligible, 4) : null,
            'revenue' => number_format($revenue, 2, '.', ''),
            'revenue_per_contact' => $eligible > 0 ? number_format($revenue / $eligible, 2, '.', '') : null,
        ];
    }

    /**
     * Diferencia de tasas de conversión (expuestos − control) con intervalo del
     * 95% por aproximación normal. Sin control o con muestras chicas: null y el motivo.
     *
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>  $h
     * @return array<string, mixed>
     */
    public function uplift(array $t, array $h): array
    {
        $nt = (int) $t['entered'] - (int) $t['filtered'];
        $nh = (int) $h['entered'] - (int) $h['filtered'];
        if ($nh === 0) {
            return ['available' => false, 'reason' => 'no_holdout'];
        }
        if ($nt < 30 || $nh < 30) {
            return ['available' => false, 'reason' => 'small_sample', 'treatment_n' => $nt, 'holdout_n' => $nh];
        }
        $pt = (int) $t['converted'] / $nt;
        $ph = (int) $h['converted'] / $nh;
        $diff = $pt - $ph;
        $se = sqrt($pt * (1 - $pt) / $nt + $ph * (1 - $ph) / $nh);

        return [
            'available' => true,
            'treatment_rate' => round($pt, 4),
            'holdout_rate' => round($ph, 4),
            'difference' => round($diff, 4),
            'ci_low' => round($diff - 1.96 * $se, 4),
            'ci_high' => round($diff + 1.96 * $se, 4),
            'relative' => $ph > 0 ? round($diff / $ph, 4) : null,
            'significant' => $diff - 1.96 * $se > 0 || $diff + 1.96 * $se < 0,
            'treatment_n' => $nt,
            'holdout_n' => $nh,
        ];
    }

    /** @return array<string, array<string, mixed>> por ID de nodo */
    private function nodes(Flow $flow, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $runIds = $this->runs($flow, $from, $to)->select('id');
        $rows = DB::table('flow_run_steps as s')
            ->join('flow_runs as r', 'r.id', '=', 's.run_id')
            ->where('s.workspace_id', $flow->workspace_id)
            ->whereIn('s.run_id', $runIds)
            ->selectRaw("s.node_id, s.outcome, COUNT(DISTINCT s.run_id) AS runs, COUNT(DISTINCT CASE WHEN r.exit_reason = 'goal_reached' THEN s.run_id END) AS converted")
            ->groupBy('s.node_id', 's.outcome')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row->node_id;
            $out[$id] ??= ['reached' => 0, 'outcomes' => [], 'converted' => 0, 'messages' => []];
            $out[$id]['outcomes'][(string) $row->outcome] = (int) $row->runs;
            $out[$id]['reached'] += (int) $row->runs;
            $out[$id]['converted'] += (int) $row->converted;
        }
        // Estado de los mensajes que salieron de cada nodo.
        $messages = DB::table('flow_run_steps as s')
            ->join('messages as m', 'm.id', '=', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(s.details, '$.message_id'))"))
            ->where('s.workspace_id', $flow->workspace_id)->whereIn('s.run_id', $runIds)->where('s.outcome', 'sent')
            ->selectRaw('s.node_id, COUNT(*) AS sent, SUM(m.delivered_at IS NOT NULL) AS delivered, SUM(m.read_at IS NOT NULL OR m.opened_at IS NOT NULL) AS opened, SUM(m.clicked_at IS NOT NULL) AS clicked, SUM(m.status = \'failed\') AS failed')
            ->groupBy('s.node_id')->get();
        foreach ($messages as $row) {
            $id = (string) $row->node_id;
            $out[$id] ??= ['reached' => 0, 'outcomes' => [], 'converted' => 0, 'messages' => []];
            $out[$id]['messages'] = ['sent' => (int) $row->sent, 'delivered' => (int) $row->delivered, 'opened' => (int) $row->opened, 'clicked' => (int) $row->clicked, 'failed' => (int) $row->failed];
        }

        return $out;
    }
}
