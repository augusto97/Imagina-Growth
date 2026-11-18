<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Push\PushNotifier;
use App\Domain\Inbox\Realtime\InboxUpdated;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Asignación de conversaciones (M11): a mano, o automática al llegar un mensaje
 * a una conversación sin dueño. La automática primero aplica las reglas del
 * espacio de trabajo (`settings.inbox.rules`: intención o etiqueta → persona) y
 * si no, reparte por turnos entre quienes están disponibles. Sin nadie
 * disponible, la conversación queda en «Sin asignar».
 */
final class AssignConversation
{
    public function __construct(private readonly InboxPresence $presence, private readonly PushNotifier $push) {}

    public function assign(Conversation $conversation, ?string $userId, string $actor): Conversation
    {
        if ($userId !== null && ! in_array($userId, self::agents($conversation->workspace_id), true)) {
            throw new InvalidArgumentException(__('Esa persona no atiende la bandeja de este espacio de trabajo.'));
        }
        if ($conversation->assignee_id === $userId) {
            return $conversation;
        }
        $conversation->forceFill(['assignee_id' => $userId])->save();
        ConversationEvent::record($conversation->workspace_id, $conversation->id, $userId === null ? 'unassigned' : 'assigned', $actor,
            $userId === null ? [] : ['user' => PrefixedId::encode('usr', $userId)]);
        event(new InboxUpdated($conversation->workspace_id, $conversation->publicId(), 'assigned', $userId));
        if ($userId !== null && $userId !== $actor) {
            $this->push->notify($conversation->workspace_id, $userId, __('Te asignaron una conversación'), '/w/'.$this->workspacePublicId($conversation).'/bandeja/'.$conversation->publicId());
        }

        return $conversation;
    }

    /** Asignación automática; devuelve a quién quedó (o null). */
    public function auto(Conversation $conversation): ?string
    {
        if ($conversation->assignee_id !== null) {
            return $conversation->assignee_id;
        }
        $workspace = Workspace::query()->find($conversation->workspace_id);
        if ($workspace === null || $workspace->setting('inbox.auto_assign', true) === false) {
            return null;
        }
        $agents = self::agents($workspace->id);
        foreach ((array) $workspace->setting('inbox.rules', []) as $rule) {
            $user = PrefixedId::decode('usr', (string) ($rule['user'] ?? ''));
            $match = (isset($rule['intent']) && $rule['intent'] === $conversation->intent)
                || (isset($rule['label']) && in_array($rule['label'], (array) $conversation->labels, true));
            if ($match && $user !== null && in_array($user, $agents, true)) {
                $this->assign($conversation, $user, 'system');

                return $user;
            }
        }
        $online = array_values(array_filter($agents, fn (string $u) => $this->presence->isOnline($workspace->id, $u)));
        if ($online === []) {
            return null;
        }
        sort($online);
        $key = RedisKey::for($workspace->id, 'inbox:rr');
        $turn = (int) Cache::increment($key);
        $user = $online[($turn - 1) % count($online)];
        $this->assign($conversation, $user, 'system');

        return $user;
    }

    /** @return list<string> usuarios del espacio de trabajo cuyo rol atiende la bandeja */
    public static function agents(string $workspaceId): array
    {
        return array_values(DB::table('workspace_user')->where('workspace_id', $workspaceId)->get(['user_id', 'role'])
            ->filter(fn ($r) => WorkspaceRole::tryFrom((string) $r->role)?->can('useInbox') === true)
            ->pluck('user_id')->map(fn ($v) => (string) $v)->all());
    }

    private function workspacePublicId(Conversation $conversation): string
    {
        return (string) Workspace::query()->find($conversation->workspace_id)?->publicId();
    }
}
