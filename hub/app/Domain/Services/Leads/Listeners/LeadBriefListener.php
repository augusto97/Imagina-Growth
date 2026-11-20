<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Listeners;

use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Services\Leads\Jobs\ExtractLeadBrief;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Cada entrante de la bandeja programa la lectura de la ficha del lead (M18). Corre
 * dentro del webhook, así que solo encola: la IA va en `ExtractLeadBrief` (cola `ai`).
 * Una baja nunca se lee.
 */
final class LeadBriefListener
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function inbound(InboundMessageReceived $event): void
    {
        if ($event->optedOutByKeyword || $event->conversationId === null) {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null || ! $this->current->run($workspace, fn (): bool => LeadSettings::for($workspace->id)->ai_brief)) {
            return;
        }
        ExtractLeadBrief::dispatch($workspace->id, $event->conversationId)->delay(now()->addSeconds(ExtractLeadBrief::DELAY_SECONDS));
    }
}
