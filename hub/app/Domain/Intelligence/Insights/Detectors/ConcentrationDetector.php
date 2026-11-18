<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #10 Concentración: el 10 % de los clientes genera al menos la mitad de las
 * ventas del último año. Es un riesgo (si se van, se va el negocio) y una
 * oportunidad de programa VIP. Sin impacto en dinero: se mide con la proporción.
 */
final class ConcentrationDetector implements Detector
{
    public function key(): string
    {
        return 'concentration';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $revenues = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->whereNotNull('contact_id')
            ->where('created_at', '>=', $now->subYear()->utc())
            ->groupBy('contact_id')->selectRaw('SUM(total_ws) AS r')->orderByDesc('r')->pluck('r')->map(fn ($v) => (float) $v)->all();
        $customers = count($revenues);
        $total = array_sum($revenues);
        if ($customers < 50 || $total <= 0) {
            return [];
        }
        $top = (int) max(1, (int) ceil($customers * 0.1));
        $topRevenue = array_sum(array_slice($revenues, 0, $top));
        $share = $topRevenue / $total;
        if ($share < (float) config('igr-insights.thresholds.concentration_share', 0.5)) {
            return [];
        }
        $loyalty = Schema::hasTable('loyalty_programs')
            && DB::table('loyalty_programs')->where('workspace_id', $workspace->id)->where('status', 'active')->exists();

        return [new InsightCandidate(
            fingerprint: 'top10',
            severity: $share >= 0.7 ? 'warning' : 'info',
            title: __('El 10 % de tus clientes genera el :share de tus ventas', ['share' => Money::percent($share, 0)]),
            facts: ['customers' => $customers, 'top_customers' => $top, 'top_revenue' => round($topRevenue, 2), 'total_revenue' => round($total, 2),
                'share' => round($share, 4), 'loyalty_program' => $loyalty, 'window_days' => 365],
            assumptions: [__('Si unos pocos clientes se van, se va buena parte de las ventas: cuídalos con beneficios y atención directa.')],
            action: $loyalty
                ? ['label' => __('Ve tus clientes campeones'), 'to' => 'segmentos?system=rfm_champions']
                : ['label' => __('Crea un programa VIP para tus mejores clientes'), 'to' => 'fidelizacion'],
            magnitude: $share,
        )];
    }
}
