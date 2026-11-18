<?php

declare(strict_types=1);

namespace App\Domain\Api\Actions;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cambia la etapa de un lead desde la API pública o un sistema externo (Imagina CRM):
 * historia del lead, fechas de ganado o perdido y los eventos `lead.stage_changed`,
 * `lead.won` y `lead.lost` (eventos.md §4.11) con `by = api`.
 *
 * [verificar] Cuando el módulo de leads (M18) publique su acción de cambio de etapa, esta
 * clase debe delegar en ella para no duplicar reglas (conversión offline al ganar, etc.).
 */
final class ChangeLeadStage
{
    public const DEFAULT_STAGES = ['new' => 'open', 'contacted' => 'open', 'qualified' => 'open', 'proposal' => 'open', 'won' => 'won', 'lost' => 'lost'];

    public function __construct(private readonly HubEvents $hubEvents) {}

    public function handle(Workspace $workspace, Lead $lead, string $stage, ?string $lostReason, string $actor): Lead
    {
        $pipeline = $lead->pipeline_id !== null ? LeadPipeline::query()->find($lead->pipeline_id) : LeadPipeline::query()->where('is_default', true)->first();
        $kind = $pipeline !== null ? ($pipeline->stage($stage) !== null ? $pipeline->kindOf($stage) : null) : (self::DEFAULT_STAGES[$stage] ?? null);
        if ($kind === null) {
            throw new InvalidArgumentException(__('La etapa «:stage» no existe en el pipeline.', ['stage' => $stage]));
        }
        $from = $lead->stage;
        if ($from === $stage) {
            return $lead;
        }

        DB::transaction(function () use ($lead, $stage, $kind, $lostReason, $from, $actor): void {
            $lead->stage = $stage;
            $lead->last_activity_at = now();
            if ($kind === 'won') {
                $lead->won_at ??= now();
                $lead->lost_at = null;
            } elseif ($kind === 'lost') {
                $lead->lost_at = now();
                $lead->lost_reason = $lostReason !== null ? mb_substr($lostReason, 0, 120) : $lead->lost_reason;
            }
            $lead->save();
            LeadActivity::log($lead, 'stage', $actor, ['from' => $from, 'to' => $stage]);
        });

        $leadId = $lead->publicId();
        $items = [['event' => 'lead.stage_changed', 'properties' => ['lead_id' => $leadId, 'from' => $from, 'to' => $stage, 'by' => 'api'], 'contact_id' => $lead->contact_id]];
        if ($kind === 'won') {
            $items[] = ['event' => 'lead.won', 'contact_id' => $lead->contact_id, 'properties' => array_filter([
                'lead_id' => $leadId,
                'value' => $lead->value_ws !== null ? (string) $lead->value_ws : null,
                'currency' => $lead->value_ws !== null ? (string) $workspace->currency : null,
                'days_to_win' => $lead->created_at !== null ? (int) $lead->created_at->diffInDays(now()) : null,
                'service' => $lead->service,
            ], fn ($v) => $v !== null)];
        } elseif ($kind === 'lost') {
            $items[] = ['event' => 'lead.lost', 'contact_id' => $lead->contact_id, 'properties' => array_filter(['lead_id' => $leadId, 'reason' => $lead->lost_reason], fn ($v) => $v !== null)];
        }
        $this->hubEvents->emitMany($workspace, $items);

        return $lead->refresh();
    }
}
