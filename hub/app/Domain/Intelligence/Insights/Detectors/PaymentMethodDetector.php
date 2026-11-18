<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Stats;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #2 Método de pago problemático: pedidos que fallan o quedan sin pagar muy
 * por encima de lo normal para ese método (PSE, transferencia, Efecty…).
 * Ventana reciente: 14 días que terminan hace 2 (un pedido pendiente más
 * nuevo todavía puede pagarse); línea base: los 90 días anteriores. La
 * contraentrega no entra: es «venta por confirmar» hasta que se cobra.
 */
final class PaymentMethodDetector implements Detector
{
    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'payment_method';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $recentEnd = $now->subDays(2);
        $recentStart = $recentEnd->subDays(14);
        $baseStart = $recentStart->subDays(90);

        $rows = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_cod', false)
            ->where('created_at', '>=', $baseStart->utc())->where('created_at', '<', $recentEnd->utc())
            ->whereNotNull('payment_method_id')
            ->groupBy('payment_method_id')
            ->selectRaw('payment_method_id AS m, MAX(payment_method_title) AS title,
                SUM(created_at < ?) AS b_n, SUM(created_at < ? AND is_paid = 0 AND status <> \'refunded\') AS b_bad,
                SUM(created_at >= ?) AS r_n, SUM(created_at >= ? AND is_paid = 0 AND status <> \'refunded\') AS r_bad', array_fill(0, 4, $recentStart->utc()))
            ->get();
        $aov = (float) DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)
            ->where('created_at', '>=', $baseStart->utc())->avg('total_ws');

        $out = [];
        foreach ($rows as $row) {
            $bN = (int) $row->b_n;
            $rN = (int) $row->r_n;
            $bBad = (int) $row->b_bad;
            $rBad = (int) $row->r_bad;
            if ($rN < 20 || $bN < 30 || $rBad < 5) {
                continue;
            }
            $baseRate = $bBad / $bN;
            $recentRate = $rBad / $rN;
            // Prueba sobre la proporción de pedidos pagados (que bajó).
            $test = Stats::proportionDrop($bN - $bBad, $bN, $rN - $rBad, $rN);
            if ($recentRate < max(0.05, 1.5 * $baseRate) || $test['p'] >= 0.05) {
                continue;
            }
            $excess = $rBad - $baseRate * $rN;
            $recovery = $this->assumptions->value('payment_recovery_rate');
            $formula = Impact::of(
                new Term(__('pedidos sin pagar de más'), round($excess, 1), 'count'),
                new Term(__('que se recupera'), $recovery, 'rate', 'payment_recovery_rate'),
                new Term(__('de ticket'), $aov, 'money'),
            );
            $title = (string) ($row->title ?: $row->m);
            $out[] = new InsightCandidate(
                fingerprint: (string) $row->m,
                severity: $recentRate >= 3 * max($baseRate, 0.01) ? 'critical' : 'warning',
                title: __(':method deja sin pagar el :recent de los pedidos (normalmente el :base)', [
                    'method' => $title, 'recent' => Money::percent($recentRate), 'base' => Money::percent($baseRate),
                ]),
                facts: [
                    'payment_method' => $row->m, 'payment_method_title' => $title,
                    'recent' => ['from' => $recentStart->toDateString(), 'to' => $recentEnd->toDateString(), 'orders' => $rN, 'unpaid' => $rBad, 'rate' => round($recentRate, 4)],
                    'baseline' => ['from' => $baseStart->toDateString(), 'to' => $recentStart->toDateString(), 'orders' => $bN, 'unpaid' => $bBad, 'rate' => round($baseRate, 4)],
                    'excess_unpaid' => round($excess, 1), 'aov' => round($aov, 2), 'p_value' => round($test['p'], 5), 'payment_recovery_rate' => $recovery,
                ],
                assumptions: [__('Pedidos sin pagar por encima de lo normal en 14 días; se recupera una parte al arreglar el método (supuesto editable).')],
                formula: $formula,
                action: ['label' => __('Revisa la configuración de :method en WooCommerce y prueba un pago', ['method' => $title]), 'to' => 'analitica/embudo'],
                periodStart: $recentStart->toDateString(),
                periodEnd: $recentEnd->toDateString(),
            );
        }

        return $out;
    }
}
