<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Queries\LeadKpis;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Indicadores de leads (M18) del periodo, en la zona del espacio de trabajo. */
final class LeadKpiController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function __invoke(Request $request, LeadKpis $kpis): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90,365'], 'pipeline' => ['nullable', 'string', 'max:40']]);
        $ws = $this->current->get();
        $pipeline = isset($data['pipeline']) ? (LeadPipeline::findByPublicId($data['pipeline']) ?? abort(404)) : Pipelines::default($ws->id);
        $tz = (string) ($ws->timezone ?? 'America/Bogota');
        $to = CarbonImmutable::now($tz)->addDay()->startOfDay();
        $from = $to->subDays((int) ($data['days'] ?? 30));
        $owner = Gate::allows('ws.viewAllContacts') ? null : (string) $request->user()?->getAuthIdentifier();

        return response()->json($kpis->handle($ws->id, $pipeline, $from, $to, $owner) + ['days' => (int) ($data['days'] ?? 30), 'currency' => $ws->currency]);
    }
}
