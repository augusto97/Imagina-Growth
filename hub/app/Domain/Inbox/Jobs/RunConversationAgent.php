<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Jobs;

use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** El agente de IA atiende un entrante (cola `ai`): responde, sugiere o pasa a un humano. */
final class RunConversationAgent implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $workspaceIdValue, public string $conversationId, public string $messageId)
    {
        $this->onQueue('ai');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(ConversationAgent $agent): void
    {
        $conversation = Conversation::query()->find($this->conversationId);
        $message = Message::query()->find($this->messageId);
        // Si alguien apagó la IA mientras esperaba en la cola, no responde.
        if ($conversation === null || $message === null || $conversation->ai_mode === 'off') {
            return;
        }
        $agent->onInbound($conversation, $message);
    }
}
