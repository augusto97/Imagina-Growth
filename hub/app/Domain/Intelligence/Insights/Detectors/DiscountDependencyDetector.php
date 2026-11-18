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
 * #14 Dependencia de descuentos en aumento: el porcentaje de pedidos con cupón de
 * los últimos 30 días sube al menos 10 puntos frente a los 90 anteriores. El
 * impacto es el descuento extra que se está regalando.
 */
final class DiscountDependencyDetector implements Detector
{
    public function key(): string
    {
        return 'discount_dependency';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $recentStart = $now->subDays(30);
        $baseStart = $recentStart->subDays(90);
        $coupon = "(coupon_codes IS NOT NULL AND coupon_codes NOT IN ('', '[]', 'null'))";
        $row = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)
            ->where('created_at', '>=', $baseStart->utc())->where('created_at', '<', $now->utc())
            ->selectRaw("SUM(created_at < ?) AS b_n, SUM(created_at < ? AND {$coupon}) AS b_c, SUM(created_at >= ?) AS r_n, SUM(created_at >= ? AND {$coupon}) AS r_c,
                SUM(CASE WHEN created_at >= ? AND {$coupon} AND total > 0 THEN discount * total_ws / total ELSE 0 END) AS r_discount,
                SUM(CASE WHEN created_at >= ? THEN total_ws ELSE 0 END) AS r_revenue,
                SUM(CASE WHEN created_at < ? AND total > 0 THEN discount * total_ws / total ELSE 0 END) AS b_discount,
                SUM(CASE WHEN created_at < ? THEN total_ws ELSE 0 END) AS b_revenue", array_fill(0, 8, $recentStart->utc()))
            ->first();
        if ($row === null) {
            return [];
        }
        $bN = (int) ($row->b_n ?? 0);
        $rN = (int) ($row->r_n ?? 0);
        if ($bN < 30 || $rN < 30) {
            return [];
        }
        $baseShare = (int) $row->b_c / $bN;
        $recentShare = (int) $row->r_c / $rN;
        $increase = $recentShare - $baseShare;
        if ($increase < (float) config('igr-insights.thresholds.discount_share_points', 0.10) || (int) $row->r_c === 0) {
            return [];
        }
        $avgDiscount = (float) $row->r_discount / (int) $row->r_c;
        $formula = Impact::of(
            new Term(__('pedidos en 30 días'), $rN, 'count'),
            new Term(__('más con cupón que antes'), round($increase, 4), 'rate'),
            new Term(__('de descuento promedio'), $avgDiscount, 'money'),
        );
        $marginBase = (float) $row->b_revenue > 0 ? (float) $row->b_discount / (float) $row->b_revenue : 0.0;
        $marginRecent = (float) $row->r_revenue > 0 ? (float) $row->r_discount / (float) $row->r_revenue : 0.0;

        return [new InsightCandidate(
            fingerprint: 'coupons',
            severity: $recentShare >= 0.5 ? 'warning' : 'opportunity',
            title: __('El :recent de tus pedidos ya usa cupón (antes, el :base)', ['recent' => Money::percent($recentShare, 0), 'base' => Money::percent($baseShare, 0)]),
            facts: ['recent' => ['orders' => $rN, 'with_coupon' => (int) $row->r_c, 'share' => round($recentShare, 4), 'discount_share_of_revenue' => round($marginRecent, 4)],
                'baseline' => ['orders' => $bN, 'with_coupon' => (int) $row->b_c, 'share' => round($baseShare, 4), 'discount_share_of_revenue' => round($marginBase, 4)],
                'increase' => round($increase, 4), 'avg_discount' => round($avgDiscount, 2)],
            assumptions: [__('No regales margen a quien compra a precio completo: limita los cupones a quien de verdad los necesita.')],
            action: ['label' => __('Ve quiénes solo compran con descuento'), 'to' => 'segmentos?system=discount_only'],
            periodStart: $recentStart->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
        )];
    }
}
