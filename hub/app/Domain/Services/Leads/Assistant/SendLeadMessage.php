<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Assistant;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Actions\SendInboxMessage;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;

/**
 * «Enviar» de un ítem de «Hoy» (M18): el vendedor manda el mensaje sugerido (o el que
 * editó) con un clic. Todo pasa por el despachador y su `SendPolicy`:
 *
 * - Ventana de 24 h abierta: mensaje libre en la conversación de la bandeja
 *   (`SendInboxMessage`, queda en el chat con su autor y marca la primera respuesta).
 * - WhatsApp con la ventana cerrada: la plantilla de marketing aprobada para
 *   seguimientos, con el texto en `seguimiento.mensaje` (y `bandeja.mensaje`):
 *   consentimiento, horario legal y frecuencia como cualquier marketing.
 * - Sin WhatsApp: correo de marketing (consentimiento, horario y frecuencia).
 *
 * La misma `client_id` nunca envía dos veces. Corre con el espacio de trabajo activo.
 */
final class SendLeadMessage
{
    public function __construct(private readonly SendInboxMessage $inbox, private readonly MessageDispatcher $dispatcher) {}

    /**
     * @return array{status: string, message_id: string|null, until: string|null, reason: string|null, channel: string|null, mode: string}
     */
    public function handle(Workspace $workspace, Lead $lead, string $text, string $userId, string $clientId): array
    {
        $contact = Contact::query()->find($lead->contact_id);
        $reach = LeadReach::for($workspace->id, $contact);
        $text = trim($text);
        $base = ['channel' => $reach['channel'], 'mode' => $reach['mode']];
        if ($reach['mode'] === 'none' || $contact === null) {
            return $base + ['status' => DispatchResult::DENIED, 'message_id' => null, 'until' => null, 'reason' => (string) $reach['reason']];
        }
        $conversation = $reach['conversation'];
        if ($reach['mode'] === 'text' && $conversation !== null) {
            $result = $this->inbox->handle($conversation, ['type' => 'text', 'client_id' => $clientId, 'text' => $text], $userId);
        } elseif ($reach['mode'] === 'template' && $conversation !== null) {
            $result = $this->inbox->handle($conversation, ['type' => 'template', 'client_id' => $clientId,
                'template_id' => PrefixedId::encode(Template::ID_PREFIX, (string) $reach['template_id']), 'variables' => ['mensaje' => $text]], $userId);
        } else {
            $email = $reach['mode'] === 'email';
            $dispatched = $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id,
                contactId: $contact->id,
                channel: $email ? Channel::Email : Channel::WhatsApp,
                // Escribirle a un lead que no está en una conversación abierta es comercial (cumplimiento.md §4.1).
                kind: MessageKind::Marketing,
                sourceType: 'inbox',
                sourceId: $lead->id,
                templateId: $email ? null : $reach['template_id'],
                context: $email
                    ? ['subject' => __('Sobre tu solicitud en :business', ['business' => $workspace->name]), 'text' => $text]
                    : ['seguimiento' => ['mensaje' => $text, 'servicio' => $lead->service], 'bandeja' => ['mensaje' => $text]],
                idempotencyKey: 'lead:'.$lead->id.':'.$clientId,
            ));
            $result = [
                'status' => $dispatched->status,
                'message_id' => $dispatched->messageId !== null ? PrefixedId::encode(Message::ID_PREFIX, $dispatched->messageId) : null,
                'until' => $dispatched->until?->toIso8601String(),
                'reason' => $dispatched->reason,
            ];
        }
        if (in_array($result['status'], [DispatchResult::QUEUED, DispatchResult::DUPLICATE], true)) {
            $lead->forceFill(['last_activity_at' => now()])->save();
            if ($result['status'] === DispatchResult::QUEUED) {
                LeadActivity::log($lead, 'message', $userId, ['channel' => $reach['channel'], 'mode' => $reach['mode'], 'from' => 'today']);
            }
        }

        return $base + ['status' => $result['status'], 'message_id' => $result['message_id'], 'until' => $result['until'], 'reason' => $result['reason']];
    }
}
