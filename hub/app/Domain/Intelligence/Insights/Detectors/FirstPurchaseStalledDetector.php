<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #9 Primera compra estancada: clientes de un pedido que ya superaron el tiempo
 * mediano hasta la segunda compra (con menos de 30 casos se usan 45 días), sin
 * el flujo «Segunda compra» activo. Solo los del último año: más atrás ya no es
 * una segunda compra sino una recuperación.
 */
final class FirstPurchaseStalledDetector implements Detector
{
    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'first_purchase_stalled';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $flow = Data::libraryFlowStatus('second_purchase');
        if ($flow === Flow::ACTIVE) {
            return [];
        }
        [$median, $pairs] = $this->medianDaysToSecond($workspace);
        $row = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('deleted_at')->whereNull('merged_into_id')
            ->where('orders_count', 1)->whereNotNull('first_order_at')
            ->where('first_order_at', '<', $now->subDays($median)->utc())->where('first_order_at', '>=', $now->subYear()->utc())
            ->selectRaw('COUNT(*) AS n, COALESCE(AVG(aov_ws), 0) AS aov')->first();
        if ($row === null) {
            return [];
        }
        $n = (int) ($row->n ?? 0);
        if ($n < 10) {
            return [];
        }
        $aov = (float) $row->aov;
        $rate = $this->assumptions->value('second_purchase_rate');
        $formula = Impact::of(
            new Term(__('clientes de una sola compra'), $n, 'count'),
            new Term(__('que hace la segunda con un flujo'), $rate, 'rate', 'second_purchase_rate'),
            new Term(__('de ticket'), $aov, 'money'),
        );

        return [new InsightCandidate(
            fingerprint: 'second',
            severity: 'opportunity',
            title: __(':n clientes compraron una vez y no han vuelto después de :days días', ['n' => Money::number($n), 'days' => $median]),
            facts: ['customers' => $n, 'median_days_to_second' => $median, 'pairs' => $pairs, 'aov' => round($aov, 2), 'second_purchase_flow' => $flow ?? 'missing'],
            assumptions: [$pairs < 30 ? __('Con pocas segundas compras para medir, se usan 45 días como referencia.') : __('El tiempo mediano sale de tus propios clientes.')],
            action: ['label' => __('Activa el flujo «Segunda compra»'), 'to' => 'flujos?tab=biblioteca', 'kind' => 'activate_flow', 'payload' => ['library_key' => 'second_purchase']],
            formula: $formula,
        )];
    }

    /** @return array{0: int, 1: int} días medianos entre el primer y el segundo pedido, y cuántos casos hubo */
    private function medianDaysToSecond(Workspace $workspace): array
    {
        $days = DB::table('orders as o2')
            ->join('orders as o1', fn ($j) => $j->on('o1.contact_id', '=', 'o2.contact_id')->where('o1.order_index', 1))
            ->where('o2.workspace_id', $workspace->id)->where('o2.order_index', 2)->whereNotNull('o2.contact_id')
            ->limit(5000)->pluck(DB::raw('DATEDIFF(o2.created_at, o1.created_at)'))->map(fn ($d) => (float) $d)->all();
        if (count($days) < 30) {
            return [45, count($days)];
        }

        return [max(7, (int) round(Data::percentile(array_values($days), 0.5))), count($days)];
    }
}
