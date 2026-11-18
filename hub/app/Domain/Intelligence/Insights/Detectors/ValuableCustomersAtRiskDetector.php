<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Intelligence\Rfm\RfmSegment;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #8 Clientes valiosos en riesgo: «No podemos perderlos» y «En riesgo» del
 * RFM, con el dinero histórico que representan (inteligencia.md §5.2).
 */
final class ValuableCustomersAtRiskDetector implements Detector
{
    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'valuable_at_risk';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $segments = [RfmSegment::CantLose->value, RfmSegment::AtRisk->value];
        $row = DB::table('contacts')->where('workspace_id', $workspace->id)->whereIn('rfm_segment', $segments)->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(revenue_total_ws), 0) AS revenue, COALESCE(AVG(aov_ws), 0) AS aov, SUM(rfm_segment = ?) AS cant_lose', [RfmSegment::CantLose->value])
            ->first();
        $n = (int) ($row->n ?? 0);
        if ($row === null || $n < 5) {
            return [];
        }
        $aov = (float) $row->aov;
        $rate = $this->assumptions->value('reactivation_rate');
        $formula = Impact::of(
            new Term(__('clientes'), $n, 'count'),
            new Term(__('de reactivación de referencia'), $rate, 'rate', 'reactivation_rate'),
            new Term(__('de ticket'), $aov, 'money'),
        );

        return [new InsightCandidate(
            fingerprint: 'rfm',
            severity: (int) $row->cant_lose >= 10 ? 'warning' : 'opportunity',
            title: __(':n clientes valiosos están en riesgo de irse; compraron :revenue', ['n' => Money::number($n), 'revenue' => Money::format($row->revenue, $workspace->currency)]),
            facts: ['customers' => $n, 'cant_lose' => (int) $row->cant_lose, 'at_risk' => $n - (int) $row->cant_lose,
                'historical_revenue' => round((float) $row->revenue, 2), 'aov' => round($aov, 2), 'reactivation_rate' => $rate],
            assumptions: [__('Estimación: :formula. No es una promesa: ajusta el supuesto con tu experiencia.', ['formula' => $formula->text($workspace->currency)])],
            action: ['label' => __('Crea una campaña de recuperación para «En riesgo»'), 'to' => 'segmentos?system=rfm_at_risk',
                'kind' => 'create_campaign', 'payload' => ['system_key' => 'rfm_at_risk', 'name' => __('Recuperación de clientes en riesgo'), 'channel' => 'email']],
            formula: $formula,
        )];
    }
}
