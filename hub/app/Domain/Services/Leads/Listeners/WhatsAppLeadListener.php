<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Listeners;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Leads desde WhatsApp (M18): una conversación de WhatsApp con intención de
 * compra de un contacto sin lead abierto crea un lead de origen `whatsapp`
 * (referencia = la conversación), si el plan incluye servicios y el espacio de
 * trabajo no lo apagó (Leads → Ajustes).
 *
 * La intención la deja el clasificador de entrantes en `conversations.intent`
 * (cola `ai`), así que se escuchan dos momentos: el entrante, cuando la
 * conversación ya traía la intención, y el guardado de la conversación cuando la
 * intención pasa a `purchase`.
 */
final class WhatsAppLeadListener
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly LeadIntake $intake, private readonly Entitlements $entitlements) {}

    public function inbound(InboundMessageReceived $event): void
    {
        if ($event->optedOutByKeyword || $event->conversationId === null) {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, function () use ($workspace, $event): void {
            $conversation = Conversation::query()->find($event->conversationId);
            if ($conversation !== null && $conversation->intent === 'purchase') {
                $this->capture($workspace, $conversation);
            }
        });
    }

    public function conversationSaved(Conversation $conversation): void
    {
        if ($conversation->intent !== 'purchase' || ! $conversation->wasChanged('intent')) {
            return;
        }
        $workspace = Workspace::query()->find($conversation->workspace_id);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, fn () => $this->capture($workspace, $conversation));
    }

    private function capture(Workspace $workspace, Conversation $conversation): void
    {
        if (! $this->entitlements->allows($workspace, 'services') || ! LeadSettings::for($workspace->id)->whatsapp_leads) {
            return;
        }
        $channel = DB::table('channel_accounts')->where('workspace_id', $workspace->id)->where('id', $conversation->channel_account_id)->value('channel');
        if ($channel !== 'whatsapp') {
            return;
        }
        if (Lead::query()->where('contact_id', $conversation->contact_id)->whereNull('won_at')->whereNull('lost_at')->exists()) {
            return;
        }
        $this->intake->capture(new LeadIntakeInput(
            workspaceId: $workspace->id,
            contactId: $conversation->contact_id,
            origin: 'whatsapp',
            originRef: $conversation->id,
            source: ['channel' => 'whatsapp'],
        ));
    }
}
