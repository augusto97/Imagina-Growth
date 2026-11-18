<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Http;

use App\Domain\Intelligence\Insights\Actions\RunInsightAction;
use App\Domain\Intelligence\Insights\Actions\UpdateAssumption;
use App\Domain\Intelligence\Insights\DetectorCatalog;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\InsightPresenter;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Hallazgos (inteligencia.md §5). Rutas en routes/api/insights.php. */
final class InsightController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{ws}/insights?status=open|actioned|dismissed|expired&category=&severity= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $status = (string) $request->query('status', 'open');
        $category = (string) $request->query('category', '');
        $severity = (string) $request->query('severity', '');
        $keys = $category !== '' ? array_column(array_filter(DetectorCatalog::all(), fn ($e) => $e['category'] === $category), 'key') : null;
        $insights = Insight::query()
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['new', 'seen']))
            ->when(in_array($status, ['dismissed', 'actioned', 'expired'], true), fn ($q) => $q->where('status', $status))
            ->when($keys !== null, fn ($q) => $q->whereIn('detector_key', $keys))
            ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
            ->orderByDesc('updated_at')->limit(200)->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
            ->values();
        $currency = $this->currency();

        return response()->json(['data' => $insights->map(fn (Insight $i) => InsightPresenter::make($i, $currency)), 'meta' => $this->summary()]);
    }

    /** GET /api/w/{ws}/insights/{insight} — marca «visto». */
    public function show(string $insight): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = Insight::findByPublicId($insight) ?? abort(404);
        if ($model->status === 'new') {
            $model->forceFill(['status' => 'seen'])->save();
        }

        return response()->json(InsightPresenter::make($model, $this->currency()));
    }

    /** PATCH /api/w/{ws}/insights/{insight} {status: dismissed|actioned|seen} */
    public function update(Request $request, string $insight, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['status' => ['required', 'in:dismissed,actioned,seen']]);
        $model = Insight::findByPublicId($insight) ?? abort(404);
        $model->forceFill(['status' => $data['status'], 'dismissed_at' => $data['status'] === 'seen' ? null : now()])->save();
        $audit->handle('insight.'.$data['status'], $model, ['detector' => $model->detector_key], $model->workspace_id);

        return response()->json(InsightPresenter::make($model, $this->currency()));
    }

    /** POST /api/w/{ws}/insights/{insight}/act — «Hacer ahora» con 1 clic. */
    public function act(Request $request, string $insight, RunInsightAction $run): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = Insight::findByPublicId($insight) ?? abort(404);
        /** @var User $user */
        $user = $request->user();
        $result = $run->handle($model, $user);

        return response()->json($result + ['insight' => InsightPresenter::make($model->refresh(), $this->currency())]);
    }

    /** GET /api/w/{ws}/insights/settings — supuestos editables y estado de cada detector. */
    public function settings(Assumptions $assumptions): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $runs = InsightEngine::runs($this->current->get());

        return response()->json([
            'assumptions' => $assumptions->describe(),
            'detectors' => array_map(fn (array $e) => [
                'number' => $e['number'], 'key' => $e['key'], 'category' => $e['category'], 'cadence' => $e['cadence'], 'phase' => $e['phase'],
                'enabled' => $e['class'] !== null,
                'last_run' => $e['class'] === null ? ['status' => 'disabled'] : ($runs[$e['key']] ?? null),
            ], DetectorCatalog::all()),
        ]);
    }

    /** PUT /api/w/{ws}/insights/assumptions/{key} {value: number|null} — null vuelve al valor por defecto. */
    public function assumption(Request $request, string $key, UpdateAssumption $update, Assumptions $assumptions): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['value' => ['present', 'nullable', 'numeric']]);
        abort_unless(in_array($key, Assumptions::keys(), true), 404);
        /** @var User $user */
        $user = $request->user();
        $updated = $update->handle($key, $data['value'] === null ? null : (float) $data['value'], $user->id);
        $assumptions->forget();

        return response()->json(['assumptions' => $assumptions->describe(), 'updated' => $updated]);
    }

    /** @return array{open: int, impact_open: float, actioned_month: int, impact_actioned_month: float, by_category: array<string, int>} */
    private function summary(): array
    {
        $open = Insight::query()->whereIn('status', ['new', 'seen'])->get(['detector_key', 'estimated_impact_ws']);
        $actioned = Insight::query()->where('status', 'actioned')->where('dismissed_at', '>=', now()->startOfMonth())->get(['estimated_impact_ws']);
        $byCategory = array_fill_keys(DetectorCatalog::CATEGORIES, 0);
        foreach ($open as $i) {
            $byCategory[DetectorCatalog::category((string) $i->detector_key)]++;
        }

        return [
            'open' => $open->count(),
            'impact_open' => round($open->sum(fn (Insight $i) => (float) $i->estimated_impact_ws), 2),
            'actioned_month' => $actioned->count(),
            'impact_actioned_month' => round($actioned->sum(fn (Insight $i) => (float) $i->estimated_impact_ws), 2),
            'by_category' => $byCategory,
        ];
    }

    private function currency(): string
    {
        return (string) ($this->current->get()->currency ?: 'COP');
    }
}
