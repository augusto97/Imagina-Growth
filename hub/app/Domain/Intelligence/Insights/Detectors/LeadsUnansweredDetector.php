<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #21 Leads sin respuesta en 15 minutos (M18): de los leads de los últimos 7 días,
 * cuántos esperaron más de 15 minutos (o siguen sin respuesta). Dinero en juego =
 * leads abiertos que siguen sin respuesta × tasa de cierre de los últimos 180 días
 * × valor promedio de un lead ganado.
 */
final class LeadsUnansweredDetector implements Detector
{
    private const MIN_LEADS = 3;

    public function key(): string
    {
        return 'leads_unanswered';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(7);
        $row = DB::table('leads')->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $since->utc())->where('created_at', '<=', $now->subMinutes(15)->utc())
            ->selectRaw('COUNT(*) AS total,
                SUM(first_response_at IS NULL OR first_response_at > DATE_ADD(created_at, INTERVAL 15 MINUTE)) AS slow,
                SUM(first_response_at IS NULL AND won_at IS NULL AND lost_at IS NULL) AS waiting')
            ->first();
        $total = (int) ($row->total ?? 0);
        $slow = (int) ($row->slow ?? 0);
        $waiting = (int) ($row->waiting ?? 0);
        if ($total < self::MIN_LEADS || $slow === 0) {
            return [];
        }
        $close = LeadStats::closeRate($workspace, $now->subDays(180), $now);
        $value = LeadStats::avgWonValue($workspace, $now->subDays(180), $now);
        $formula = $waiting > 0 && $close > 0 && $value > 0 ? Impact::of(
            new Term(__('leads abiertos sin respuesta'), $waiting, 'count'),
            new Term(__('de cierre (180 días)'), round($close, 4), 'rate'),
            new Term(__('de valor por lead ganado'), $value, 'money'),
        ) : null;

        return [new InsightCandidate(
            fingerprint: 'week',
            severity: $waiting >= 3 || $slow / $total >= 0.5 ? 'warning' : 'opportunity',
            title: __(':slow de :total leads de la semana esperaron más de 15 minutos por una respuesta', ['slow' => Money::number($slow), 'total' => Money::number($total)]),
            facts: ['leads_7d' => $total, 'slow_over_15m' => $slow, 'waiting_now' => $waiting, 'slow_share' => round($slow / $total, 4), 'close_rate_180d' => round($close, 4), 'avg_won_value' => round($value, 2), 'window_days' => 7],
            assumptions: [__('Un lead que recibe respuesta en los primeros minutos tiene muchas más probabilidades de cerrar; el cálculo usa tu propia tasa de cierre.')],
            action: ['label' => __('Responde los leads que esperan'), 'to' => 'leads?status=open&sort=activity', 'kind' => 'open_leads'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
            magnitude: (float) $slow,
        )];
    }
}
