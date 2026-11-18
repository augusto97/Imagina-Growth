<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Services\Leads\Crm\CrmSync;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Eventos del hub de leads (eventos.md §4.6, esquema v5): `lead.created`,
 * `lead.stage_changed`, `lead.won` y `lead.lost`, con el mismo ID de evento si
 * se repiten (idempotentes). Alimentan flujos (18), segmentos, webhooks (M19) y
 * el envío al CRM externo.
 */
final class LeadEvents
{
    public function __construct(private readonly HubEvents $hubEvents, private readonly CrmSync $crm) {}

    public function created(Workspace $workspace, Lead $lead): void
    {
        $this->emit($workspace, $lead, 'lead.created', [
            'lead_id' => $lead->publicId(),
            'origin' => $lead->origin,
            'service' => $lead->service,
            'score' => $lead->score,
            'temperature' => $lead->temperature,
            'channel' => is_string($lead->source['channel'] ?? null) ? mb_substr($lead->source['channel'], 0, 32) : null,
            'form_id' => is_string($lead->source['form_id'] ?? null) ? $lead->source['form_id'] : null,
            'value' => $lead->value_ws,
            'currency' => $lead->value_ws !== null ? $workspace->currency : null,
        ], 'created:'.$lead->id);
        $this->crm($lead, 'created');
    }

    public function stageChanged(Workspace $workspace, Lead $lead, string $from, string $to, string $by, int $seq): void
    {
        $this->emit($workspace, $lead, 'lead.stage_changed', ['lead_id' => $lead->publicId(), 'from' => $from, 'to' => $to, 'by' => $by], "stage:{$lead->id}:{$seq}");
        $this->crm($lead, 'stage_changed');
    }

    public function won(Workspace $workspace, Lead $lead): void
    {
        $days = $lead->won_at !== null && $lead->created_at !== null ? (int) max(0, floor($lead->created_at->diffInDays($lead->won_at))) : null;
        $this->emit($workspace, $lead, 'lead.won', [
            'lead_id' => $lead->publicId(),
            'value' => $lead->value_ws,
            'currency' => $lead->value_ws !== null ? $workspace->currency : null,
            'days_to_win' => $days,
            'service' => $lead->service,
        ], 'won:'.$lead->id.':'.$lead->won_at?->getTimestamp());
        $this->crm($lead, 'won');
    }

    public function lost(Workspace $workspace, Lead $lead): void
    {
        $this->emit($workspace, $lead, 'lead.lost', ['lead_id' => $lead->publicId(), 'reason' => $lead->lost_reason], 'lost:'.$lead->id.':'.$lead->lost_at?->getTimestamp());
        $this->crm($lead, 'lost');
    }

    /** @param array<string, mixed> $properties */
    private function emit(Workspace $workspace, Lead $lead, string $event, array $properties, string $key): void
    {
        $site = $lead->site_id !== null ? Site::query()->find($lead->site_id) : null;
        try {
            $this->hubEvents->emit($workspace, $event, $properties, $lead->contact_id, $site, now()->toImmutable(), self::eventId($workspace->id, $key));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param 'created'|'stage_changed'|'won'|'lost' $event */
    private function crm(Lead $lead, string $event): void
    {
        try {
            $external = $this->crm->push($lead, $event);
        } catch (Throwable $e) {
            report($e);

            return;
        }
        if ($external !== null && $lead->external_crm_id === null) {
            $lead->forceFill(['external_crm_id' => mb_substr($external, 0, 64)])->saveQuietly();
        }
    }

    /** UUID determinista: el mismo cambio no se duplica si se reintenta. */
    public static function eventId(string $workspaceId, string $key): string
    {
        return Uuid::uuid5('6f1c2a90-7d4e-5b3a-9c1e-1a2b3c4d5e6f', $workspaceId.':lead:'.$key)->toString();
    }
}
