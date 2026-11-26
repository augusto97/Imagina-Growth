<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversiones desde el servidor a Meta, Google y TikTok (M06): los destinos de Medición. Conectar una cuenta de
 * anuncios necesita sus credenciales (Conexiones), así que activar solo reanuda los destinos en pausa; sin destinos,
 * la estrategia pide conectar uno. Un destino activo con el último envío fallido deja la estrategia en «atención».
 */
final class AdConversionsBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'ad_destinations';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $rows = $this->destinations($ctx);
        $active = $rows->where('status', 'active');
        if ($active->isNotEmpty()) {
            return BlockState::on('medicion', $active->contains(fn ($d) => $d->last_error !== null) ? ['destination_error'] : []);
        }

        return $rows->isNotEmpty() ? BlockState::paused('medicion') : BlockState::off('medicion');
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $this->set($ctx, 'paused', 'active', $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $this->set($ctx, 'active', 'paused', $userId);

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        $platforms = $this->destinations($ctx)->pluck('platform')->unique()->map(fn ($p) => ucfirst((string) $p))->implode(', ');

        return Previews::summary([
            __('strategies.preview.ads_server'),
            $platforms !== '' ? __('strategies.preview.ads_platforms', ['platforms' => $platforms]) : __('strategies.preview.ads_connect'),
        ]);
    }

    /** @return Collection<int, \stdClass> */
    private function destinations(StrategyContext $ctx): Collection
    {
        return $ctx->remember('ad_destinations', fn () => DB::table('ad_destinations')->where('workspace_id', $ctx->id())->get(['id', 'platform', 'status', 'last_error']));
    }

    private function set(StrategyContext $ctx, string $from, string $to, ?string $userId): void
    {
        foreach (AdDestination::query()->where('status', $from)->get() as $destination) {
            $destination->forceFill(['status' => $to])->save();
            app(RecordAuditLog::class)->handle('measurement.destination_updated', $destination, ['fields' => ['status'], 'credentials' => [], 'status' => $to], $ctx->id(), null, $userId);
        }
    }
}
