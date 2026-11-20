<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Services\Leads\Qualification\QualificationRules;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Leads → Calificación (M18, auditoría §5.1 función 3): los juegos de preguntas que el
 * agente de ventas hace en el chat y sus reglas de derivación. Ver con `useLeads`;
 * crear, cambiar y borrar con `manageServices`.
 */
final class LeadQualificationController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $ws = $this->current->get();
        $site = BookingSettings::site($ws);

        return response()->json([
            'sets' => LeadQualificationSet::query()->orderBy('position')->orderBy('created_at')->get()->map(fn (LeadQualificationSet $s) => self::present($s))->values()->all(),
            'pipelines' => LeadPipeline::query()->orderByDesc('is_default')->orderBy('created_at')->get(['id', 'name'])
                ->map(fn (LeadPipeline $p) => ['id' => $p->publicId(), 'name' => $p->name])->values()->all(),
            'links' => ['catalog' => $site?->getAttribute('url'), 'booking' => BookingSettings::pageUrl($ws)],
            'can_manage' => Gate::allows('ws.manageServices'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $data = QualificationRules::normalize((array) $request->all());
        $set = LeadQualificationSet::query()->create($data + [
            'workspace_id' => $this->current->id(),
            'position' => (int) LeadQualificationSet::query()->max('position') + 1,
        ]);

        return response()->json(self::present($set), 201);
    }

    public function update(Request $request, string $set): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        $model = LeadQualificationSet::findByPublicId($set) ?? abort(404);
        $model->forceFill(QualificationRules::normalize((array) $request->all()))->save();

        return response()->json(self::present($model));
    }

    public function destroy(string $set): JsonResponse
    {
        Gate::authorize('ws.manageServices');
        (LeadQualificationSet::findByPublicId($set) ?? abort(404))->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(LeadQualificationSet $s): array
    {
        return [
            'id' => $s->publicId(),
            'name' => $s->name,
            'pipeline_id' => $s->pipeline_id !== null ? LeadPipeline::ID_PREFIX.'_'.$s->pipeline_id : null,
            'services' => array_values((array) ($s->services ?? [])),
            'active' => $s->active,
            'questions' => $s->questions,
            'routing' => $s->routing,
        ];
    }
}
