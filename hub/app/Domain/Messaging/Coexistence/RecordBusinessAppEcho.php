<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Coexistence;

use App\Domain\Inbox\Actions\UpdateConversation;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Messaging\Channels\Data\BusinessAppMessage;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Log;

/**
 * Lo que el negocio escribe desde la app de WhatsApp Business del celular (`smb_message_echoes`, coexistencia):
 * aparece en la conversación de la bandeja como saliente «Desde el celular» y cuenta como respuesta de una persona.
 *
 * - No pasa por `MessageDispatcher` (ya salió), no gasta cupos de frecuencia y no se cobra (BusinessAppMessages).
 * - La conversación deja de esperar respuesta (`awaiting_since`, así «Hoy» no la muestra pendiente), sus no leídos
 *   quedan en 0 y, si es la primera respuesta del ciclo, se mide. La primera respuesta del lead la marca
 *   `FirstResponseListener` (también acepta `business_app`).
 * - Si la IA estaba en modo automático, pasa a una persona (`handoff`, motivo `business_app`): no contesta encima del dueño.
 * - Un mensaje editado o eliminado en la app cambia el que ya estaba guardado.
 * - Sin teléfono ni BSUID conocido del cliente no hay a quién ligarlo y se ignora.
 */
final class RecordBusinessAppEcho
{
    public const HANDOFF_REASON = 'business_app';

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly BusinessAppContacts $contacts,
        private readonly UpdateConversation $update,
    ) {}

    public function handle(ChannelAccount $account, BusinessAppMessage $m): ?Message
    {
        $workspace = Workspace::query()->find($account->workspace_id);
        if ($workspace === null) {
            return null;
        }

        return $this->current->run($workspace, function () use ($workspace, $account, $m): ?Message {
            if ($m->type === 'edit' || $m->type === 'revoke') {
                BusinessAppMessages::amend($m);

                return null;
            }
            if (BusinessAppMessages::known($m->providerMessageId)) {
                return null;  // Meta reintentó el webhook
            }
            $resolved = $this->contacts->resolve($workspace, $m->phone, $m->userId);
            if ($resolved === null) {
                Log::notice('igr.messaging.echo_unmatched', ['account' => $account->id]);

                return null;
            }
            $contact = $resolved[0];
            $conversation = Conversation::query()->firstOrNew(['channel_account_id' => $account->id, 'contact_id' => $contact->id]);
            if (! $conversation->exists) {
                $conversation->fill(['workspace_id' => $workspace->id, 'status' => 'open'])->save();
            }
            $message = BusinessAppMessages::store($workspace, $account, $contact, $conversation, $m, BusinessAppMessages::ECHO);
            if ($message !== null) {
                $this->answered($conversation, $message, $m);
            }

            return $message;
        });
    }

    private function answered(Conversation $c, Message $message, BusinessAppMessage $m): void
    {
        $opened = $c->opened_at;
        $first = $c->first_response_seconds === null && $opened !== null;
        $c->forceFill([
            'last_outbound_at' => $m->at,
            'last_message_at' => now(),
            'unread_count' => 0,
            'awaiting_since' => null,
            'status' => $c->status === 'closed' ? 'open' : $c->status,
        ]);
        if ($first) {
            $c->forceFill(['first_response_seconds' => max(0, (int) $m->at->diffInSeconds($opened, true))]);
        }
        $c->save();
        ConversationEvent::record($c->workspace_id, $c->id, 'message', BusinessAppMessages::ACTOR, ['message' => $message->publicId(), 'type' => $m->type]);
        if ($first) {
            ConversationEvent::record($c->workspace_id, $c->id, 'first_response', BusinessAppMessages::ACTOR, ['seconds' => $c->first_response_seconds]);
        }
        if ($c->ai_mode === 'auto') {
            $this->update->handoff($c, self::HANDOFF_REASON, BusinessAppMessages::ACTOR);  // avisa a la bandeja

            return;
        }
        event(new InboxUpdated($c->workspace_id, $c->publicId(), 'outbound', $c->assignee_id));
    }
}
