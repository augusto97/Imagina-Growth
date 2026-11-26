<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Actions;

use App\Domain\Measurement\Audiences\Jobs\SyncAudienceJob;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;

/**
 * Sincroniza un segmento con una plataforma de anuncios (M06, audiencias de la Fase 3): crea la audiencia, la deja en
 * la auditoría y encola la primera sincronización. Los atajos (`PRESETS`) usan los segmentos del sistema o crean el de
 * «Compraron en los últimos 30 días». Lo usan Medición y la estrategia «Audiencias sincronizadas».
 */
final class CreateAudienceSync
{
    public const PRESETS = [
        'recent_buyers_exclusion' => ['purpose' => 'exclusion'],
        'high_value_seed' => ['purpose' => 'lookalike_seed', 'system' => 'rfm_champions'],
        'at_risk_reactivation' => ['purpose' => 'target', 'system' => 'rfm_at_risk'],
    ];

    public function __construct(private readonly CurrentWorkspace $current, private readonly RecordAuditLog $audit, private readonly SaveSegment $save) {}

    /** @throws ValidationException si el segmento ya se sincroniza con ese destino */
    public function handle(AdDestination $destination, Segment $segment, string $purpose, ?string $name, ?string $userId): AudienceSync
    {
        if (AudienceSync::query()->where('segment_id', $segment->id)->where('destination_id', $destination->id)->exists()) {
            throw ValidationException::withMessages(['segment_id' => __('Ese segmento ya se sincroniza con este destino.')]);
        }
        $sync = AudienceSync::query()->create([
            'workspace_id' => $this->current->id(),
            'segment_id' => $segment->id,
            'destination_id' => $destination->id,
            'name' => $name ?? mb_substr('Imagina Growth · '.$segment->name, 0, 160),
            'purpose' => $purpose,
            'created_by' => $userId,
        ]);
        $this->audit->handle('measurement.audience_created', $sync, ['segment' => $segment->publicId(), 'platform' => $destination->platform->value, 'purpose' => $purpose], $this->current->id());
        SyncAudienceJob::dispatch($this->current->id(), $sync->id);

        return $sync->refresh();
    }

    /** El segmento de un atajo: el del sistema, o el de compradores recientes (lo crea si hace falta y hay quién lo cree). */
    public function presetSegment(string $preset, ?User $user): ?Segment
    {
        $system = self::PRESETS[$preset]['system'] ?? null;
        if ($system !== null) {
            return Segment::query()->where('system_key', $system)->first();
        }
        $name = __('Compraron en los últimos 30 días');
        $existing = Segment::query()->where('name', $name)->where('is_system', false)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($user === null) {
            return null;
        }

        return $this->save->handle(null, [
            'name' => $name,
            'description' => __('Para excluir de los anuncios a quien acaba de comprar.'),
            'folder' => __('Anuncios'),
            'definition' => ['op' => 'and', 'conditions' => [['type' => 'purchase', 'field' => 'last_order_at', 'operator' => 'within_last_days', 'value' => 30]]],
        ], $user);
    }
}
