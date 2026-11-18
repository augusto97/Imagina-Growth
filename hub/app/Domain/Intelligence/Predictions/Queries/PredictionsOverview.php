<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Queries;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\MlClient;
use App\Domain\Intelligence\Predictions\Support\Reliability;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** La pantalla de predicciones: estado y precisión de cada modelo, cobertura y distribuciones. */
final class PredictionsOverview
{
    public function __construct(private readonly MlClient $ml) {}

    /** @return array<string, mixed> */
    public function handle(Workspace $workspace): array
    {
        $runs = PredictionRun::latestByModel($workspace->id);
        $models = [];
        foreach (PredictionRun::MODELS as $key) {
            $run = $runs[$key] ?? null;
            $models[] = [
                'key' => $key,
                'status' => $run !== null ? $run->status : 'never',
                'trained_at' => $run?->trained_at?->toIso8601String(),
                'reason' => $run !== null ? ($run->metrics['reason'] ?? null) : null,
                'metrics' => $run !== null && $run->metrics !== null ? $run->metrics : (object) [],
                'params' => $key === 'best_hour' ? ($run->params ?? null) : null,
                'reliability' => Reliability::level($run),
            ];
        }
        $base = fn () => DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('deleted_at')->whereNull('merged_into_id');
        $now = now();

        return [
            'models' => $models,
            'ml_configured' => $this->ml->configured(),
            'coverage' => [
                'contacts' => $base()->count(),
                'customers' => $base()->where('orders_count', '>', 0)->count(),
                'with_clv' => $base()->whereNotNull('predicted_clv_12m_ws')->count(),
                'with_churn' => $base()->whereNotNull('churn_band')->count(),
                'with_next_order' => $base()->whereNotNull('expected_next_order_at')->count(),
                'with_affinity' => $base()->whereNotNull('category_affinity')->where('category_affinity', '!=', '[]')->count(),
            ],
            'distributions' => [
                'churn' => $base()->whereNotNull('churn_band')->groupBy('churn_band')->selectRaw('churn_band AS k, count(*) AS n')->pluck('n', 'k'),
                'p_alive' => $this->histogram(array_values($base()->whereNotNull('p_alive')->pluck('p_alive')->map(fn ($v) => (float) $v)->all())),
                'next_order' => [
                    'overdue' => $base()->where('expected_next_order_at', '<', $now)->where('expected_next_order_at', '>=', $now->copy()->subDays(180))->count(),
                    'next_7' => $base()->whereBetween('expected_next_order_at', [$now, $now->copy()->addDays(7)])->count(),
                    'next_30' => $base()->whereBetween('expected_next_order_at', [$now->copy()->addDays(7), $now->copy()->addDays(30)])->count(),
                    'later' => $base()->where('expected_next_order_at', '>', $now->copy()->addDays(30))->count(),
                ],
                'clv_total' => (string) $base()->sum('predicted_clv_12m_ws'),
            ],
            'thresholds' => (array) config('igr-predictions.thresholds'),
            'holdout_days' => (int) config('igr-predictions.holdout_days', 90),
        ];
    }

    /**
     * @param  list<float>  $values  entre 0 y 1
     * @return list<array{from: float, to: float, n: int}>
     */
    private function histogram(array $values): array
    {
        $bins = array_fill(0, 10, 0);
        foreach ($values as $v) {
            $bins[min(9, (int) floor($v * 10))]++;
        }

        return array_map(fn (int $i) => ['from' => $i / 10, 'to' => ($i + 1) / 10, 'n' => $bins[$i]], array_keys($bins));
    }
}
