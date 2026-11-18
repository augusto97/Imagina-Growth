<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Algo cambió en una conversación (entrante, envío, asignación, estado). Va por
 * el canal privado `workspace.{id}.inbox` (hub/CLAUDE.md §13) solo con IDs: la
 * bandeja vuelve a pedir lo que necesita. Sin Reverb configurado, la SPA consulta
 * cada 5 s.
 */
final class InboxUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'default';

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $conversationId,
        public readonly string $reason,
        public readonly ?string $assigneeId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->workspaceId.'.inbox')];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return ['conversation' => $this->conversationId, 'reason' => $this->reason, 'assignee' => $this->assigneeId];
    }
}
