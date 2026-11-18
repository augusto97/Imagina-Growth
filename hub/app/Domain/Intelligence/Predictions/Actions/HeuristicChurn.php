<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Intelligence\Predictions\Support\OrderHistory;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Abandono sin modelo (inteligencia.md §4.2, versión de la Fase 2): `ratio = días
 * desde la última compra / intervalo esperado`; < 1 bajo, 1–2 medio, > 2 alto.
 * Se usa cuando BG/NBD no tiene datos suficientes. `churn_risk` = ratio / 3,
 * entre 0 y 1, para ordenar.
 */
final class HeuristicChurn
{
    /**
     * @param  array<string, float>  $intervals  de ComputeExpectedNextOrder
     * @return array{status: string, reason?: string}
     */
    public function handle(Workspace $workspace, array $intervals): array
    {
        $history = OrderHistory::daily($workspace->id);
        $repeat = count(array_filter($history, fn (array $d) => count($d) >= 2));
        $min = (int) config('igr-predictions.thresholds.heuristic_repeat_customers', 50);
        if ($repeat < $min || $intervals === []) {
            $reason = __('Hay :n clientes con 2 o más compras; el riesgo de abandono necesita :min.', ['n' => $repeat, 'min' => $min]);
            PredictionRun::record($workspace->id, 'churn', 'insufficient_data', ['reason' => $reason, 'repeat_customers' => $repeat]);

            return ['status' => 'insufficient_data', 'reason' => $reason];
        }
        $now = intdiv(now()->getTimestamp(), 86400) * 86400;
        $rows = [];
        $bands = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($history as $cid => $days) {
            $interval = $intervals[$cid] ?? null;
            if ($interval === null || $days === []) {
                continue;
            }
            $ratio = (($now - $days[count($days) - 1][0]) / 86400) / max(1.0, $interval);
            $band = $ratio < 1 ? 'low' : ($ratio <= 2 ? 'medium' : 'high');
            $bands[$band]++;
            $rows[$cid] = ['churn_band' => $band, 'churn_risk' => round(min(1.0, $ratio / 3), 4)];
        }
        Bulk::contacts($workspace->id, $rows);
        PredictionRun::record($workspace->id, 'churn', 'heuristic', ['customers' => count($rows), 'bands' => $bands, 'reason' => __('Riesgo por días sin comprar frente al intervalo esperado (sin modelo todavía).')]);

        return ['status' => 'heuristic'];
    }
}
