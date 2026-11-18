<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Actions;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Models\LeadSettings;
use Illuminate\Support\Facades\DB;

/**
 * Asigna el vendedor de un lead (M18): por turnos entre los vendedores activos
 * (al que hace más tiempo no recibe uno) o por reglas de servicio (los que
 * atienden un servicio cuyo nombre aparece en el del lead; si nadie coincide,
 * por turnos). Solo cuentan vendedores que siguen en el equipo. Corre con el
 * espacio de trabajo activo.
 */
final class AssignLead
{
    public function handle(Lead $lead, LeadSettings $settings): ?string
    {
        $mode = (string) ($settings->assignmentRules()['mode'] ?? 'round_robin');
        if ($mode === 'none') {
            return null;
        }
        $members = DB::table('workspace_user')->where('workspace_id', $lead->workspace_id)->pluck('user_id')->map(fn ($v) => (string) $v)->all();
        $sellers = LeadSeller::query()->where('active', true)->whereIn('user_id', $members)
            ->orderByRaw('last_assigned_at IS NOT NULL')->orderBy('last_assigned_at')->orderBy('id')->get();
        if ($sellers->isEmpty()) {
            return null;
        }
        $pick = null;
        if ($mode === 'rules' && $lead->service !== null) {
            $service = mb_strtolower($lead->service);
            $pick = $sellers->first(function (LeadSeller $s) use ($service): bool {
                foreach ($s->services ?? [] as $word) {
                    $word = mb_strtolower(trim((string) $word));
                    if ($word !== '' && str_contains($service, $word)) {
                        return true;
                    }
                }

                return false;
            });
        }
        $pick ??= $sellers->first();
        if (! $pick instanceof LeadSeller) {
            return null;
        }
        $pick->forceFill(['last_assigned_at' => now()])->save();

        return $pick->user_id;
    }
}
