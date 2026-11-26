<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Measurement\Audiences\Actions\CreateAudienceSync;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audiencias sincronizadas (M06, Fase 3) con los atajos de Medición: la semilla de públicos similares con los
 * clientes de más valor (RFM «Campeones») y la reactivación de «En riesgo», en el primer destino activo, con
 * CreateAudienceSync. Las audiencias respetan el consentimiento de anuncios (AudienceEligibility). Pausar las pone en
 * pausa; reanudar las vuelve a prender.
 */
final class AudiencesBlock implements StrategyBlock
{
    private const PRESETS = ['high_value_seed', 'at_risk_reactivation'];

    public function kind(): string
    {
        return 'audiences';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $rows = $this->syncs($ctx);
        $live = $rows->whereIn('status', ['active', 'pending']);
        if ($live->isNotEmpty()) {
            return BlockState::on('medicion?tab=audiencias', $live->contains('status', 'error') || $rows->contains('status', 'error') ? ['destination_error'] : []);
        }

        return $rows->where('status', 'paused')->isNotEmpty() ? BlockState::paused('medicion?tab=audiencias') : BlockState::off('medicion?tab=audiencias');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $paused = AudienceSync::query()->where('status', 'paused')->get();
        if ($paused->isNotEmpty()) {
            foreach ($paused as $sync) {
                $sync->forceFill(['status' => $sync->last_synced_at !== null ? 'active' : 'pending'])->save();
                app(RecordAuditLog::class)->handle('measurement.audience_updated', $sync, ['status' => $sync->status], $ctx->id(), null, $userId);
            }

            return [];
        }
        $destination = AdDestination::query()->where('status', 'active')->orderBy('created_at')->first()
            ?? throw new StrategyNotReady(__('strategies.requirements.ad_destination.missing'), [], [], 'medicion');
        $create = app(CreateAudienceSync::class);
        $user = $userId !== null ? User::query()->find($userId) : null;
        foreach (self::PRESETS as $preset) {
            $segment = $create->presetSegment($preset, $user);
            if ($segment === null || AudienceSync::query()->where('segment_id', $segment->id)->where('destination_id', $destination->id)->exists()) {
                continue;
            }
            $create->handle($destination, $segment, CreateAudienceSync::PRESETS[$preset]['purpose'], null, $userId);
        }

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        foreach (AudienceSync::query()->whereIn('status', ['active', 'pending'])->get() as $sync) {
            $sync->forceFill(['status' => 'paused'])->save();
            app(RecordAuditLog::class)->handle('measurement.audience_updated', $sync, ['status' => 'paused'], $ctx->id(), null, $userId);
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        return Previews::summary([__('strategies.preview.audiences_seed'), __('strategies.preview.audiences_at_risk'), __('strategies.preview.audiences_consent')]);
    }

    /** @return Collection<int, \stdClass> */
    private function syncs(StrategyContext $ctx): Collection
    {
        return $ctx->remember('audience_syncs', fn () => DB::table('audience_syncs')->where('workspace_id', $ctx->id())->get(['id', 'status']));
    }
}
