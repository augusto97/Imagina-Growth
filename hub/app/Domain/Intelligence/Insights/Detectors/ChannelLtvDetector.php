<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #11 Canal con mejor LTV: lo que vale un cliente a 180 días según el canal de
 * su primer pedido (clientes que empezaron hace entre 180 días y 2 años, al
 * menos 30 por canal). «Los clientes de Google valen 2,1× más a 180 días que los
 * de Meta». El costo de adquisición entra cuando haya gasto en anuncios por
 * canal [verificar].
 */
final class ChannelLtvDetector implements Detector
{
    private const MIN_CUSTOMERS = 30;

    public function key(): string
    {
        return 'channel_ltv';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $rows = DB::table('orders as f')
            ->join('orders as o', fn ($j) => $j->on('o.contact_id', '=', 'f.contact_id')->where('o.is_paid', true))
            ->where('f.workspace_id', $workspace->id)->where('f.is_first_order', true)->where('f.is_paid', true)
            ->whereNotNull('f.contact_id')->whereNotNull('f.channel_first')->where('f.channel_first', '!=', '')
            ->where('f.created_at', '<', $now->subDays(180)->utc())->where('f.created_at', '>=', $now->subYears(2)->utc())
            ->whereRaw('o.created_at < DATE_ADD(f.created_at, INTERVAL 180 DAY)')
            ->groupBy('f.channel_first')
            ->selectRaw('f.channel_first AS channel, COUNT(DISTINCT f.contact_id) AS customers, SUM(o.total_ws) / COUNT(DISTINCT f.contact_id) AS ltv')
            ->havingRaw('customers >= ?', [self::MIN_CUSTOMERS])->get();
        if ($rows->count() < 2) {
            return [];
        }
        $sorted = $rows->sortByDesc(fn ($r) => (float) $r->ltv)->values();
        $best = $sorted->first();
        $worst = $sorted->last();
        if ($best === null || $worst === null) {
            return [];
        }
        $ratio = (float) $worst->ltv > 0 ? (float) $best->ltv / (float) $worst->ltv : 0.0;
        if ($ratio < (float) config('igr-insights.thresholds.ltv_ratio', 1.5)) {
            return [];
        }
        $label = fn (string $c) => __('insight.channel.'.$c) === 'insight.channel.'.$c ? $c : __('insight.channel.'.$c);

        return [new InsightCandidate(
            fingerprint: 'ltv180',
            severity: 'opportunity',
            title: __('Los clientes de :best valen :ratio× más a 180 días que los de :worst', [
                'best' => $label((string) $best->channel), 'worst' => $label((string) $worst->channel), 'ratio' => number_format($ratio, 1, ',', '.'),
            ]),
            facts: ['channels' => $sorted->map(fn ($r) => ['channel' => (string) $r->channel, 'customers' => (int) $r->customers, 'ltv_180' => round((float) $r->ltv, 2)])->all(),
                'best_channel' => (string) $best->channel, 'worst_channel' => (string) $worst->channel, 'ratio' => round($ratio, 2), 'window_days' => 180],
            assumptions: [__('Compara lo que compra cada cliente en sus primeros 180 días según el canal por el que llegó. Mueve presupuesto hacia el canal que trae mejores clientes.')],
            action: ['label' => __('Revisa los canales en Analítica'), 'to' => 'analitica/canales'],
            magnitude: $ratio,
        )];
    }
}
