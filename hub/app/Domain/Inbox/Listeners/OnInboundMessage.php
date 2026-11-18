<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Listeners;

use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Inbox\Jobs\RunConversationAgent;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Models\MessageAttachment;
use App\Domain\Inbox\Push\PushNotifier;
use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Cada entrante en la bandeja (M11): no leídos, reabre el ciclo si estaba
 * cerrada, intención del mensaje, adjuntos, asignación automática, aviso en
 * tiempo real y push a quien la atiende, y el agente de IA si la conversación lo
 * tiene encendido (cola `ai`).
 *
 * Corre dentro del webhook de Meta, así que la intención sale de las palabras
 * clave (`HeuristicInboundClassifier`, sin llamar al modelo): sirve para las reglas
 * de asignación. Después, `ClassifyInboundMessage` (en cola) la afina con IA.
 */
final class OnInboundMessage
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly HeuristicInboundClassifier $classifier,
        private readonly AssignConversation $assign,
        private readonly PushNotifier $push,
    ) {}

    public function handle(InboundMessageReceived $event): void
    {
        if ($event->conversationId === null) {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, function () use ($workspace, $event): void {
            $conversation = Conversation::query()->find($event->conversationId);
            $message = Message::query()->find($event->messageId);
            if ($conversation === null || $message === null) {
                return;
            }
            $payload = (array) $message->payload;
            $text = (string) ($payload['text'] ?? '');
            $now = now();
            $wasOpen = $conversation->status === 'open' && $conversation->getAttribute('opened_at') !== null;

            $intent = $event->optedOutByKeyword ? 'optout' : ($text !== '' ? $this->classifier->classify($workspace->id, $text)->intent : null);
            // Nueva para la bandeja (sin historia): toma el modo de IA por defecto del agente.
            $fresh = ! ConversationEvent::query()->where('conversation_id', $conversation->id)->exists();
            $conversation->forceFill([
                'unread_count' => (int) $conversation->unread_count + 1,
                'last_message_at' => $now,
                'status' => 'open',
                'intent' => $intent ?? $conversation->intent,
                'awaiting_since' => $conversation->getAttribute('awaiting_since') ?? $now,
            ]);
            if (! $wasOpen) {
                $conversation->forceFill(['opened_at' => $now, 'resolved_at' => null, 'first_response_seconds' => null]);
            }
            if ($fresh) {
                $conversation->forceFill(['ai_mode' => self::defaultAiMode($workspace->id)]);
            }
            $conversation->save();
            if (! $wasOpen) {
                ConversationEvent::record($workspace->id, $conversation->id, 'opened', 'system');
            }
            if (is_array($payload['media'] ?? null) && in_array($payload['type'] ?? '', ['image', 'audio', 'video', 'document', 'sticker'], true)) {
                MessageAttachment::query()->create([
                    'workspace_id' => $workspace->id, 'message_id' => $message->id, 'kind' => (string) $payload['type'],
                    'mime' => $payload['media']['mime'] ?? null, 'provider_media_id' => $payload['media']['id'] ?? null,
                    'caption' => $text !== '' ? $text : null, 'created_at' => $now,
                ]);
            }

            $assignee = $this->assign->auto($conversation);
            event(new InboxUpdated($workspace->id, $conversation->publicId(), 'inbound', $assignee));
            if ($assignee !== null) {
                $this->push->notify($workspace->id, $assignee, __('Nuevo mensaje en la bandeja'), '/w/'.$workspace->publicId().'/bandeja/'.$conversation->publicId());
            }
            if (in_array($conversation->ai_mode, ['assist', 'auto'], true) && $intent !== 'optout') {
                RunConversationAgent::dispatch($workspace->id, $conversation->id, $message->id);
            }
        });
    }

    /** Modo de IA de las conversaciones nuevas: el de los ajustes del agente, si está activo. */
    public static function defaultAiMode(string $workspaceId): string
    {
        $row = DB::table('ai_agent_settings')->where('workspace_id', $workspaceId)->first(['enabled', 'default_mode']);
        if ($row === null || ! (bool) $row->enabled) {
            return 'off';
        }

        return in_array($row->default_mode, ['assist', 'auto'], true) ? (string) $row->default_mode : 'off';
    }
}
