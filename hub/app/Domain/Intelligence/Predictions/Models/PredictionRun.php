<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una corrida de un modelo (inteligencia.md §4): su precisión contra el periodo de
 * reserva (`metrics`), los parámetros y el estado: `completed`, `heuristic`
 * (respaldo sin modelo), `insufficient_data` (con el motivo en `metrics.reason`)
 * o `failed`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $model edno | churn | clv | propensity | affinity | next_product | price_band | best_hour | engagement | rfm
 * @property Carbon $trained_at
 * @property array<string, mixed>|null $metrics
 * @property array<string, mixed>|null $params
 * @property string $status
 */
class PredictionRun extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'pr';

    public const MODELS = ['edno', 'churn', 'clv', 'propensity', 'affinity', 'next_product', 'price_band', 'best_hour', 'engagement'];

    protected $table = 'prediction_runs';

    protected $fillable = ['workspace_id', 'model', 'trained_at', 'metrics', 'params', 'status'];

    protected function casts(): array
    {
        return ['trained_at' => 'datetime', 'metrics' => 'array', 'params' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $params
     */
    public static function record(string $workspaceId, string $model, string $status, array $metrics = [], array $params = []): self
    {
        return self::query()->create([
            'workspace_id' => $workspaceId, 'model' => $model, 'trained_at' => now(),
            'metrics' => $metrics, 'params' => $params, 'status' => $status,
        ]);
    }

    /** @return array<string, self> la última corrida de cada modelo */
    public static function latestByModel(string $workspaceId): array
    {
        $out = [];
        foreach (self::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)->whereIn('model', self::MODELS)->orderByDesc('trained_at')->orderByDesc('id')->limit(200)->get() as $run) {
            $out[$run->model] ??= $run;
        }

        return $out;
    }
}
