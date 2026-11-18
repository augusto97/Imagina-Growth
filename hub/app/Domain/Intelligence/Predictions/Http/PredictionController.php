<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Http;

use App\Domain\Intelligence\Predictions\Jobs\RunPredictionsJob;
use App\Domain\Intelligence\Predictions\Queries\PredictionsOverview;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Pantalla Inteligencia → Predicciones (M17). Rutas en routes/api/predictions.php. */
final class PredictionController
{
    public const PRESETS = ['high_value_at_risk', 'buying_soon', 'disengaged'];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET predictions */
    public function index(PredictionsOverview $overview): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json($overview->handle($this->current->get()));
    }

    /** POST predictions/run {train} — recalcula en la cola. */
    public function run(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $train = $request->boolean('train');
        RunPredictionsJob::dispatch($this->current->id(), $train);
        $audit->handle('predictions.run', null, ['train' => $train], $this->current->id());

        return response()->json(['queued' => true], 202);
    }

    /** POST predictions/segments {preset} — crea el segmento con las condiciones de predicción. */
    public function segment(Request $request, SaveSegment $save): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['preset' => ['required', Rule::in(self::PRESETS)]]);
        $workspace = $this->current->get();
        $p75 = $this->p75Clv($workspace->id);
        [$name, $description, $conditions] = match ($data['preset']) {
            'high_value_at_risk' => [__('Alto valor y en riesgo'), __('CLV previsto en el 25% más alto y riesgo de abandono medio o alto.'), [
                ['type' => 'prediction', 'field' => 'churn_band', 'operator' => 'in', 'value' => ['medium', 'high']],
                ['type' => 'prediction', 'field' => 'predicted_clv_12m', 'operator' => 'gte', 'value' => $p75],
            ]],
            'buying_soon' => [__('Compran pronto'), __('Su próxima compra esperada cae en los próximos 14 días.'), [
                ['type' => 'prediction', 'field' => 'expected_next_order_at', 'operator' => 'within_next_days', 'value' => 14],
            ]],
            default => [__('Sin interacción'), __('Puntaje de interacción menor que 10: buenos candidatos para suprimir de los envíos.'), [
                ['type' => 'prediction', 'field' => 'engagement_score', 'operator' => 'lt', 'value' => 10],
            ]],
        };
        /** @var User $user */
        $user = $request->user();
        $segment = $save->handle(null, ['name' => $name, 'description' => $description, 'definition' => ['op' => 'and', 'conditions' => $conditions]], $user);

        return response()->json(['id' => $segment->publicId(), 'name' => $segment->name], 201);
    }

    private function p75Clv(string $workspaceId): float
    {
        $values = DB::table('contacts')->where('workspace_id', $workspaceId)->whereNotNull('predicted_clv_12m_ws')
            ->where('predicted_clv_12m_ws', '>', 0)->orderBy('predicted_clv_12m_ws')->pluck('predicted_clv_12m_ws')->map(fn ($v) => (float) $v)->all();

        return $values === [] ? 0.0 : round($values[(int) floor(count($values) * 0.75)], 2);
    }
}
