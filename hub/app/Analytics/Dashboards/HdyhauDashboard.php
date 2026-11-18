<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * 12. «¿Cómo nos conociste?»: respuestas de la encuesta de la página de gracias
 * frente al canal del último clic del pedido. Muestra lo que la atribución
 * digital no ve: voz a voz, radio, tienda física.
 */
final class HdyhauDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        $rows = DB::table('survey_responses as r')
            ->join('survey_definitions as d', 'd.id', '=', 'r.survey_id')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.workspace_id', $workspace->id)->where('d.kind', 'hdyhau')
            ->where('r.created_at', '>=', $period->fromUtc())->where('r.created_at', '<', $period->toUtc())
            ->when($filters->site !== null, fn ($q) => $q->where('o.site_id', $filters->site?->id))
            ->selectRaw("r.answer AS answer, COALESCE(o.channel_last, 'unknown') AS channel, COUNT(*) AS n, COALESCE(SUM(o.total_ws), 0) AS revenue")
            ->groupByRaw("r.answer, COALESCE(o.channel_last, 'unknown')")
            ->get();

        $answers = [];
        $matrix = [];
        $total = 0;
        foreach ($rows as $r) {
            $answer = (string) ($r->answer ?? 'otro');
            $answers[$answer]['responses'] = ($answers[$answer]['responses'] ?? 0) + (int) $r->n;
            $answers[$answer]['revenue'] = ($answers[$answer]['revenue'] ?? 0) + (float) $r->revenue;
            $matrix[] = ['answer' => $answer, 'channel' => (string) $r->channel, 'responses' => (int) $r->n];
            $total += (int) $r->n;
        }
        $list = [];
        foreach ($answers as $answer => $a) {
            $list[] = ['answer' => $answer, 'responses' => $a['responses'], 'share' => self::ratio($a['responses'], $total), 'revenue' => round($a['revenue'], 2)];
        }
        usort($list, fn ($a, $b) => $b['responses'] <=> $a['responses']);
        $orders = (int) $this->paidOrders($workspace, $period, $filters)->count();

        return ['responses' => $total, 'response_rate' => self::ratio($total, $orders), 'answers' => $list, 'matrix' => $matrix];
    }
}
