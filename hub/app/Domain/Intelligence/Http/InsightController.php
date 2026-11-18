<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Http;

use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Hallazgos (inteligencia.md §5): lista, detalle, marcar visto, descartar o marcar hecho. */
final class InsightController
{
    /** GET /api/w/{ws}/insights?status=open|all */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $status = $request->query('status', 'open');
        $insights = Insight::query()
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['new', 'seen']))
            ->when(in_array($status, ['dismissed', 'actioned', 'expired'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')->limit(200)->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
            ->values();

        return response()->json(['data' => $insights->map(fn (Insight $i) => self::present($i))]);
    }

    /** GET /api/w/{ws}/insights/{insight} — marca «visto». */
    public function show(string $insight): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = Insight::findByPublicId($insight) ?? abort(404);
        if ($model->status === 'new') {
            $model->forceFill(['status' => 'seen'])->save();
        }

        return response()->json(self::present($model));
    }

    /** PATCH /api/w/{ws}/insights/{insight} {status: dismissed|actioned|seen} */
    public function update(Request $request, string $insight, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate(['status' => ['required', 'in:dismissed,actioned,seen']]);
        $model = Insight::findByPublicId($insight) ?? abort(404);
        $model->forceFill(['status' => $data['status'], 'dismissed_at' => $data['status'] === 'seen' ? null : now()])->save();
        $audit->handle('insight.'.$data['status'], $model, ['detector' => $model->detector_key], $model->workspace_id);

        return response()->json(self::present($model));
    }

    /** @return array<string, mixed> */
    public static function present(Insight $i): array
    {
        return [
            'id' => $i->publicId(),
            'detector' => $i->detector_key,
            'severity' => $i->severity,
            'title' => $i->title,
            'facts' => $i->facts,
            'impact' => $i->estimated_impact_ws,
            'assumptions' => $i->impact_assumptions ?? [],
            'action' => $i->recommended_action,
            'status' => $i->status,
            'period_start' => $i->getAttribute('period_start')?->toDateString(),
            'period_end' => $i->getAttribute('period_end')?->toDateString(),
            'created_at' => $i->created_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }
}
