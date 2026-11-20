<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyTier;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recalcula el nivel de quienes pueden tener uno: los que ya tienen (pueden
 * bajar) y los que compraron o tienen puntos en los últimos 12 meses. Lo usan el
 * job nocturno y el guardado de los niveles en el panel.
 */
final class RecalculateAllTiers
{
    public function __construct(private readonly EvaluateTier $tiers, private readonly CurrentWorkspace $current) {}

    /** @return int contactos que cambiaron de nivel */
    public function handle(LoyaltyProgram $program, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $tierList = LoyaltyTier::query()->where('program_id', $program->id)->orderBy('rank')->get();
        $base = DB::table('contacts')->where('workspace_id', $this->current->id())->whereNull('merged_into_id')->whereNull('deleted_at');
        if ($tierList->isEmpty()) {
            // Sin niveles: nadie queda con uno viejo.
            return $base->whereNotNull('tier_id')->update(['tier_id' => null]);
        }
        // Un nivel borrado deja de existir para sus contactos.
        (clone $base)->whereNotNull('tier_id')->whereNotIn('tier_id', $tierList->pluck('id')->all())->update(['tier_id' => null]);
        $since = $now->subMonths(12);
        $changed = 0;
        $base->where(fn ($q) => $q->whereNotNull('tier_id')->orWhere('last_order_at', '>=', $since)->orWhere('points_balance', '>', 0))
            // Por clave: el callback cambia tier_id, que está en el filtro; con OFFSET se saltaban contactos (R12).
            ->select('id')->chunkById(1000, function ($rows) use ($tierList, $now, &$changed): void {
                foreach ($rows as $row) {
                    try {
                        if ($this->tiers->handle((string) $row->id, $tierList, $now)['changed']) {
                            $changed++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return $changed;
    }
}
