<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Intelligence\Predictions\Support\MlClient;
use App\Domain\Intelligence\Predictions\Support\OrderHistory;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Abandono y CLV con BG/NBD × Gamma-Gamma (inteligencia.md §4.2–4.3) en el
 * servicio Python: se ajusta con la historia hasta hace 90 días, se valida contra
 * lo que pasó en esos 90 días y se vuelve a ajustar con todo para predecir.
 * Escribe `p_alive`, `expected_orders_90d/365d`, `churn_risk`, `churn_band`,
 * `predicted_clv_12m_ws` y `predictions_at`, y una corrida `churn` y otra `clv`.
 * Si el servicio falla, las predicciones anteriores se quedan.
 */
final class TrainLifetimeModels
{
    public function __construct(private readonly MlClient $ml) {}

    /** @return array{status: string, reason?: string} */
    public function handle(Workspace $workspace): array
    {
        $history = OrderHistory::daily($workspace->id);
        // Todo en días enteros: el corte es el inicio del día de hoy (UTC).
        $now = intdiv(now()->getTimestamp(), 86400) * 86400;
        $customers = count($history);
        $firstEver = $history === [] ? $now : min(array_map(fn (array $d) => $d[0][0], $history));
        $months = (int) floor(($now - $firstEver) / 86400 / 30.44);
        $minCustomers = (int) config('igr-predictions.thresholds.clv_customers', 500);
        $minMonths = (int) config('igr-predictions.thresholds.clv_history_months', 12);
        if ($customers < $minCustomers || $months < $minMonths) {
            $reason = __('Hay :n clientes con compras y :m meses de historia; el modelo necesita :min clientes y :months meses.', ['n' => $customers, 'm' => $months, 'min' => $minCustomers, 'months' => $minMonths]);
            foreach (['churn', 'clv'] as $model) {
                PredictionRun::record($workspace->id, $model, 'insufficient_data', ['reason' => $reason, 'customers' => $customers, 'history_months' => $months]);
            }

            return ['status' => 'insufficient_data', 'reason' => $reason];
        }

        $holdoutDays = (int) config('igr-predictions.holdout_days', 90);
        $cutoff = $now - $holdoutDays * 86400;
        $calibration = [];
        $actual = [];
        $full = [];
        foreach ($history as $cid => $days) {
            $s = OrderHistory::summary($days, $cutoff);
            if ($s !== null) {
                $calibration[] = [$cid, ...$s];
                $after = array_values(array_filter($days, fn (array $d) => $d[0] > $cutoff));
                if ($after !== []) {
                    $actual[$cid] = [count($after), round(array_sum(array_column($after, 1)), 2)];
                }
            }
            $f = OrderHistory::summary($days, $now);
            if ($f !== null) {
                $full[] = [$cid, ...$f];
            }
        }

        try {
            $result = $this->ml->fitLifetime([
                'calibration' => ['customers' => $calibration],
                'holdout' => ['days' => $holdoutDays, 'actual' => (object) $actual],
                'full' => ['customers' => $full],
                'horizons' => [90, 365],
                'clv_horizon' => 365,
            ]);
        } catch (RuntimeException $e) {
            Log::warning('igr.predictions.ml_failed', ['workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            foreach (['churn', 'clv'] as $model) {
                PredictionRun::record($workspace->id, $model, 'failed', ['reason' => $e->getMessage()]);
            }

            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }

        $v = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        [$high, $medium, $calibrated] = self::bands((array) ($v['alive_by_decile'] ?? []));
        $rows = [];
        $bands = ['low' => 0, 'medium' => 0, 'high' => 0];
        $at = now()->toDateTimeString();
        foreach ((array) $result['predictions'] as $p) {
            $alive = (float) $p['p_alive'];
            $band = $alive < $high ? 'high' : ($alive < $medium ? 'medium' : 'low');
            $bands[$band]++;
            $rows[(string) $p['id']] = [
                'p_alive' => round($alive, 4),
                'churn_risk' => round(1 - $alive, 4),
                'churn_band' => $band,
                'expected_orders_90d' => round((float) ($p['exp_90'] ?? 0), 3),
                'expected_orders_365d' => round((float) ($p['exp_365'] ?? 0), 3),
                'predicted_clv_12m_ws' => round((float) $p['clv'], 2),
                'predictions_at' => $at,
            ];
        }
        Bulk::contacts($workspace->id, $rows);

        $params = (array) ($result['params'] ?? []);
        PredictionRun::record($workspace->id, 'churn', 'completed', [
            'customers' => count($rows), 'holdout_days' => $holdoutDays,
            'auc' => $v['churn_auc'] ?? null, 'active_in_holdout_pct' => $v['active_in_holdout_pct'] ?? null,
            'calibration_customers' => $v['customers'] ?? null, 'bands' => $bands,
            'purchase_auc' => $v['purchase_auc'] ?? null, 'alive_by_decile' => $v['alive_by_decile'] ?? [],
        ], ['bgnbd' => $params['bgnbd'] ?? null, 'model' => $v['model'] ?? null, 'candidates' => $v['candidates'] ?? null,
            'bands' => ['high_below' => $high, 'medium_below' => $medium, 'calibrated' => $calibrated]]);
        PredictionRun::record($workspace->id, 'clv', 'completed', [
            'customers' => count($rows), 'holdout_days' => $holdoutDays,
            'purchases' => $v['purchases'] ?? null, 'purchases_by_decile' => $v['purchases_by_decile'] ?? [],
            'revenue' => $v['revenue'] ?? null, 'revenue_by_decile' => $v['revenue_by_decile'] ?? [],
            'seconds' => $result['seconds'] ?? null,
        ], $params);

        return ['status' => 'completed'];
    }

    /**
     * Bandas de abandono calibradas con la reserva (inteligencia.md §4.2): de los
     * deciles de P(activo), de menor a mayor, «alto» son los que volvieron a comprar
     * a la mitad de la tasa general o menos, y «medio» los que quedaron por debajo
     * de la tasa general. Sin calibración, los cortes de `igr-predictions.churn_bands`.
     *
     * @param  array<int, mixed>  $deciles
     * @return array{0: float, 1: float, 2: bool}
     */
    public static function bands(array $deciles): array
    {
        $high = (float) config('igr-predictions.churn_bands.high_below', 0.3);
        $medium = (float) config('igr-predictions.churn_bands.medium_below', 0.7);
        $rows = array_values(array_filter($deciles, fn ($d) => is_array($d) && isset($d['active_rate'], $d['p_alive_max'], $d['customers'])));
        $total = array_sum(array_column($rows, 'customers'));
        if (count($rows) < 4 || $total === 0) {
            return [$high, $medium, false];
        }
        $rate = array_sum(array_map(fn (array $d) => $d['active_rate'] * $d['customers'], $rows)) / $total;
        if ($rate <= 0) {
            return [$high, $medium, false];
        }
        $calHigh = null;
        $calMedium = null;
        $highOpen = true;
        foreach ($rows as $d) {
            $r = (float) $d['active_rate'];
            if ($r >= $rate) {
                break;
            }
            if ($highOpen && $r <= $rate / 2) {
                $calHigh = (float) $d['p_alive_max'];
            } else {
                $highOpen = false;
            }
            $calMedium = (float) $d['p_alive_max'];
        }
        if ($calMedium === null) {
            return [$high, $medium, false];
        }

        // «Por debajo de» excluye el corte: se suma un poco para incluir el decil completo.
        return [$calHigh !== null ? $calHigh + 0.00005 : 0.0, $calMedium + 0.00005, true];
    }
}
