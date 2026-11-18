<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Models\ConversationNote;
use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Messaging\Models\Conversation;
use InvalidArgumentException;

/**
 * Cambios de una conversación desde la bandeja, cada uno con su evento:
 * estado (al cerrar se mide la resolución del ciclo), etiquetas, modo de IA,
 * traspaso a un humano, leído y notas internas.
 */
final class UpdateConversation
{
    public const STATUSES = ['open', 'pending', 'closed'];

    public const AI_MODES = ['off', 'assist', 'auto'];

    public function __construct(private readonly AssignConversation $assign) {}

    public function status(Conversation $c, string $status, string $actor): Conversation
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(__('Estado no válido.'));
        }
        if ($c->status === $status) {
            return $c;
        }
        $data = ['from' => $c->status, 'to' => $status];
        if ($status === 'closed') {
            $opened = $c->opened_at;
            $c->forceFill(['resolved_at' => now(), 'unread_count' => 0, 'awaiting_since' => null]);
            if ($opened !== null) {
                $data['resolution_seconds'] = (int) now()->diffInSeconds($opened, true);
            }
        }
        if ($status === 'open' && $c->status === 'closed') {
            $c->forceFill(['opened_at' => now(), 'resolved_at' => null, 'first_response_seconds' => null]);
        }
        $c->forceFill(['status' => $status])->save();
        ConversationEvent::record($c->workspace_id, $c->id, 'status', $actor, $data);
        event(new InboxUpdated($c->workspace_id, $c->publicId(), 'status', $c->assignee_id));

        return $c;
    }

    /** @param list<string> $labels */
    public function labels(Conversation $c, array $labels, string $actor): Conversation
    {
        $clean = array_values(array_unique(array_filter(array_map(fn ($l) => mb_substr(trim((string) $l), 0, 32), $labels), fn ($l) => $l !== '')));
        if (count($clean) > 10) {
            throw new InvalidArgumentException(__('Máximo 10 etiquetas por conversación.'));
        }
        $before = array_values((array) $c->labels);
        $c->forceFill(['labels' => $clean])->save();
        ConversationEvent::record($c->workspace_id, $c->id, 'label', $actor, ['added' => array_values(array_diff($clean, $before)), 'removed' => array_values(array_diff($before, $clean))]);
        event(new InboxUpdated($c->workspace_id, $c->publicId(), 'label', $c->assignee_id));

        return $c;
    }

    public function aiMode(Conversation $c, string $mode, string $actor): Conversation
    {
        if (! in_array($mode, self::AI_MODES, true)) {
            throw new InvalidArgumentException(__('Modo de IA no válido.'));
        }
        if ($c->ai_mode === $mode) {
            return $c;
        }
        $c->forceFill(['ai_mode' => $mode, 'handoff_reason' => $mode === 'off' ? $c->handoff_reason : null])->save();
        ConversationEvent::record($c->workspace_id, $c->id, 'ai_mode', $actor, ['mode' => $mode]);
        event(new InboxUpdated($c->workspace_id, $c->publicId(), 'ai_mode', $c->assignee_id));

        return $c;
    }

    /**
     * Pasa la conversación a una persona: apaga la IA, guarda el motivo y, si no
     * tiene dueño, la asigna. La llama el agente de IA (`actor = ai`) o un humano.
     */
    public function handoff(Conversation $c, string $reason, string $actor): Conversation
    {
        $c->forceFill(['ai_mode' => 'off', 'handoff_reason' => mb_substr($reason, 0, 64), 'status' => 'open'])->save();
        ConversationEvent::record($c->workspace_id, $c->id, 'handoff', $actor, ['reason' => mb_substr($reason, 0, 64)]);
        $this->assign->auto($c);
        event(new InboxUpdated($c->workspace_id, $c->publicId(), 'handoff', $c->assignee_id));

        return $c;
    }

    public function markRead(Conversation $c): Conversation
    {
        if ((int) $c->unread_count > 0) {
            $c->forceFill(['unread_count' => 0])->save();
            event(new InboxUpdated($c->workspace_id, $c->publicId(), 'read', $c->assignee_id));
        }

        return $c;
    }

    public function note(Conversation $c, string $body, string $userId): ConversationNote
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new InvalidArgumentException(__('La nota debe tener entre 1 y 4.000 caracteres.'));
        }
        $note = ConversationNote::query()->create(['workspace_id' => $c->workspace_id, 'conversation_id' => $c->id, 'user_id' => $userId, 'body' => $body]);
        ConversationEvent::record($c->workspace_id, $c->id, 'note', $userId);
        event(new InboxUpdated($c->workspace_id, $c->publicId(), 'note', $c->assignee_id));

        return $note;
    }
}
