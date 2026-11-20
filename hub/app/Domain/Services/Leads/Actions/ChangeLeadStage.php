<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Actions;

use App\Domain\Services\Leads\Jobs\SendLeadConversion;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Support\LeadEvents;
use App\Domain\Services\Leads\Support\LeadScorer;
use App\Domain\Services\Leads\Support\Pipelines;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Mueve un lead de etapa (M18). Ganado guarda `won_at` (y el valor si viene),
 * emite `lead.won` y encola la conversión offline a Meta y Google; perdido
 * guarda `lost_at` y el motivo y emite `lead.lost`; volver a una etapa abierta
 * limpia ambos. Salir de la primera etapa a mano marca la primera respuesta.
 * Corre con el espacio de trabajo activo.
 */
final class ChangeLeadStage
{
    public function __construct(private readonly LeadEvents $events) {}

    /** @param 'user'|'system'|'ai'|'api' $by */
    public function handle(Workspace $workspace, Lead $lead, string $to, string $by = 'user', ?string $actor = null, ?string $value = null, ?string $lostReason = null): Lead
    {
        $pipeline = Pipelines::for($lead);
        $stage = $pipeline->stage($to);
        if ($stage === null) {
            throw ValidationException::withMessages(['stage' => __('Esa etapa no existe en el pipeline.')]);
        }
        $from = $lead->stage;
        if ($value !== null && is_numeric($value) && (float) $value >= 0) {
            $lead->value_ws = number_format((float) $value, 2, '.', '');
        }
        if ($from === $to) {
            $lead->save();

            return $lead;
        }
        $now = now();
        $lead->stage = $to;
        $lead->stage_changed_at = $now;
        $lead->last_activity_at = $now;
        if ($stage['kind'] === 'won') {
            $lead->won_at = $now;
            $lead->lost_at = null;
            $lead->lost_reason = null;
        } elseif ($stage['kind'] === 'lost') {
            $lead->lost_at = $now;
            $lead->won_at = null;
            $lead->lost_reason = $lostReason !== null && trim($lostReason) !== '' ? mb_substr(trim($lostReason), 0, 120) : null;
        } else {
            $lead->won_at = null;
            $lead->lost_at = null;
            $lead->lost_reason = null;
        }
        if ($lead->first_response_at === null && $by === 'user' && $stage['kind'] !== 'lost' && $from === Pipelines::firstOpen($pipeline)) {
            $lead->first_response_at = $now;
        }
        $lead->save();
        if ($lead->isOpen()) {
            LeadScorer::mirror($lead);
        }
        $seq = LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'stage')->count() + 1;
        LeadActivity::log($lead, 'stage', $actor ?? $by, array_filter(['from' => $from, 'to' => $to, 'reason' => $lead->lost_reason, 'value' => $stage['kind'] === 'won' ? $lead->value_ws : null], fn ($v) => $v !== null));

        $this->events->stageChanged($workspace, $lead, $from, $to, $by, $seq);
        if ($stage['kind'] === 'won') {
            $this->events->won($workspace, $lead);
            SendLeadConversion::dispatch($workspace->id, $lead->id)->afterCommit();
        } elseif ($stage['kind'] === 'lost') {
            $this->events->lost($workspace, $lead);
        }

        return $lead;
    }
}
