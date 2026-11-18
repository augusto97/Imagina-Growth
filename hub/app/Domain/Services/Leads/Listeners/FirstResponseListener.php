<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Listeners;

use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Primera respuesta de un lead (M18): el primer mensaje que una persona del
 * equipo le escribe al contacto desde la bandeja (`source_type = inbox`) marca
 * `first_response_at` del lead abierto. Mover el lead de la primera etapa también
 * la marca (ChangeLeadStage).
 */
final class FirstResponseListener
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function created(Message $message): void
    {
        if ($message->direction !== 'out' || $message->source_type !== 'inbox' || $message->contact_id === null) {
            return;
        }
        $workspace = Workspace::query()->find($message->workspace_id);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, function () use ($message): void {
            $lead = Lead::query()->where('contact_id', $message->contact_id)->whereNull('won_at')->whereNull('lost_at')
                ->whereNull('first_response_at')->orderByDesc('created_at')->first();
            if ($lead === null) {
                return;
            }
            $lead->forceFill(['first_response_at' => now(), 'last_activity_at' => now()])->save();
            LeadActivity::log($lead, 'message', 'system', ['first_response' => true, 'channel' => $message->channel->value]);
        });
    }
}
