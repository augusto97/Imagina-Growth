<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Http;

use App\Domain\Measurement\Audiences\Actions\CreateAudienceSync;
use App\Domain\Measurement\Audiences\AudienceEligibility;
use App\Domain\Measurement\Audiences\Jobs\SyncAudienceJob;
use App\Domain\Measurement\Audiences\Models\AudienceMember;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Audiences\Platforms\AudiencePlatforms;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Audiencias (M06, Fase 3) en Medición → Audiencias. Ver: viewDashboards.
 * Crear, pausar, sincronizar o borrar: manageMarketing.
 *
 * Atajos (`preset`): exclusión de compradores recientes (crea o reutiliza el
 * segmento «Compraron en los últimos 30 días»), semilla de públicos similares
 * con los clientes de más valor (RFM «Campeones», hasta que llegue el CLV de la
 * Fase 4) y reactivación de clientes en riesgo (RFM «En riesgo»).
 */
final class AudienceController
{
    /** @var array<string, array{purpose: string, system?: string}> */
    private const PRESETS = CreateAudienceSync::PRESETS;

    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit) {}

    /** GET /api/w/{ws}/measurement/audiences */
    public function index(AudiencePlatforms $platforms): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $syncs = AudienceSync::query()->orderBy('created_at')->get();
        $destinations = AdDestination::query()->orderBy('created_at')->get();
        $segments = Segment::query()->orderByDesc('is_system')->orderBy('name')->get(['id', 'name', 'member_count', 'is_system', 'system_key']);
        $byId = $segments->keyBy('id');
        $workspace = $this->current->get();

        return response()->json([
            'data' => $syncs->map(fn (AudienceSync $s) => self::present($s, $destinations->firstWhere('id', $s->destination_id), $byId[$s->segment_id] ?? null))->values(),
            'destinations' => $destinations->map(fn (AdDestination $d) => [
                'id' => $d->publicId(),
                'platform' => $d->platform->value,
                'name' => $d->name,
                'active' => $d->isActive(),
                'missing' => $platforms->for($d->platform)->missing($d),
            ])->values(),
            'segments' => $segments->map(fn (Segment $s) => ['id' => $s->publicId(), 'name' => $s->name, 'members' => (int) $s->member_count, 'system_key' => $s->system_key])->values(),
            'presets' => array_map(fn (string $key, array $p) => ['key' => $key, 'purpose' => $p['purpose'], 'system_key' => $p['system'] ?? null], array_keys(self::PRESETS), self::PRESETS),
            'consent_rule' => AudienceEligibility::requiresExplicitConsent($workspace) ? 'explicit' : 'unless_revoked',
            'min_recommended_size' => (int) config('igr-audiences.min_recommended_size', 100),
        ]);
    }

    /** POST /api/w/{ws}/measurement/audiences */
    public function store(Request $request, CreateAudienceSync $create): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'destination_id' => ['required', 'string', 'max:40'],
            'segment_id' => ['required_without:preset', 'nullable', 'string', 'max:40'],
            'preset' => ['nullable', Rule::in(array_keys(self::PRESETS))],
            'purpose' => ['nullable', Rule::in(AudienceSync::PURPOSES)],
            'name' => ['nullable', 'string', 'max:160'],
        ]);
        $destination = AdDestination::findByPublicId($data['destination_id']);
        if ($destination === null) {
            throw ValidationException::withMessages(['destination_id' => __('Ese destino de anuncios no existe.')]);
        }
        $user = $request->user();
        $segment = ! empty($data['preset'])
            ? $create->presetSegment((string) $data['preset'], $user instanceof User ? $user : null)
            : Segment::findByPublicId((string) $data['segment_id']);
        if ($segment === null) {
            throw ValidationException::withMessages(['segment_id' => __('Ese segmento no existe.')]);
        }
        $purpose = $data['purpose'] ?? (! empty($data['preset']) ? self::PRESETS[$data['preset']]['purpose'] : 'target');
        $sync = $create->handle($destination, $segment, $purpose, $data['name'] ?? null, $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null);

        return response()->json(self::present($sync, $destination, $segment), 201);
    }

    /** PATCH /api/w/{ws}/measurement/audiences/{audience} */
    public function update(Request $request, string $audience): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $sync = AudienceSync::findByPublicId($audience) ?? abort(404);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160'], 'status' => ['sometimes', Rule::in(['active', 'paused'])], 'purpose' => ['sometimes', Rule::in(AudienceSync::PURPOSES)]]);
        if (isset($data['status']) && $data['status'] === 'active' && $sync->status === 'paused') {
            $data['status'] = $sync->last_synced_at !== null ? 'active' : 'pending';
        }
        $sync->fill($data)->save();
        $this->audit->handle('measurement.audience_updated', $sync, $data, $this->current->id());
        if (($data['status'] ?? null) !== null && $sync->status !== 'paused') {
            SyncAudienceJob::dispatch($this->current->id(), $sync->id);
        }

        return response()->json(self::present($sync, AdDestination::query()->find($sync->destination_id), Segment::query()->find($sync->segment_id)));
    }

    /** POST /api/w/{ws}/measurement/audiences/{audience}/sync */
    public function sync(string $audience): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $sync = AudienceSync::findByPublicId($audience) ?? abort(404);
        if ($sync->status === 'paused') {
            throw ValidationException::withMessages(['status' => __('La audiencia está en pausa: actívala para sincronizarla.')]);
        }
        SyncAudienceJob::dispatch($this->current->id(), $sync->id);

        return response()->json(['queued' => true], 202);
    }

    /** DELETE /api/w/{ws}/measurement/audiences/{audience}: deja de sincronizar; la audiencia sigue en la plataforma. */
    public function destroy(string $audience): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $sync = AudienceSync::findByPublicId($audience) ?? abort(404);
        DB::transaction(function () use ($sync): void {
            AudienceMember::query()->where('audience_sync_id', $sync->id)->delete();
            $sync->delete();
        });
        $this->audit->handle('measurement.audience_deleted', $sync, ['name' => $sync->name], $this->current->id());

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    public static function present(AudienceSync $s, ?AdDestination $destination, ?Segment $segment): array
    {
        return [
            'id' => $s->publicId(),
            'name' => $s->name,
            'purpose' => $s->purpose,
            'status' => $s->status,
            'platform' => $destination?->platform->value,
            'destination' => $destination !== null ? ['id' => $destination->publicId(), 'name' => $destination->name] : null,
            'segment' => $segment !== null ? ['id' => $segment->publicId(), 'name' => $segment->name, 'members' => (int) $segment->member_count] : null,
            'platform_audience_id' => $s->platform_audience_id,
            'members_count' => $s->members_count,
            'matched_count' => $s->matched_count,
            'last_added' => $s->last_added,
            'last_removed' => $s->last_removed,
            'skipped_no_consent' => $s->skipped_no_consent,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'last_attempt_at' => $s->last_attempt_at?->toIso8601String(),
            'next_sync_at' => $s->status === 'paused' ? null : self::nextRun()->toIso8601String(),
            'last_error' => $s->last_error,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /** Próxima corrida programada (02:37 y 14:37 en la zona del servidor). */
    private static function nextRun(): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        foreach ([$now->setTime(2, 37), $now->setTime(14, 37), $now->addDay()->setTime(2, 37)] as $at) {
            if ($at->gt($now)) {
                return $at;
            }
        }

        return $now->addDay()->setTime(2, 37);
    }
}
