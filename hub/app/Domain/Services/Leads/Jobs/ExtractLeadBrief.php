<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Jobs;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Llena la ficha del lead con la conversación (cola `ai`, nunca dentro del
 * webhook). Sale un minuto después del entrante y es único por conversación hasta
 * que empieza: una ráfaga de mensajes («hola», «necesito…», «para el viernes»)
 * se lee una sola vez, ya completa. Solo lleva IDs: el texto se lee cifrado de la base.
 */
final class ExtractLeadBrief implements ShouldBeUniqueUntilProcessing, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DELAY_SECONDS = 60;

    public int $tries = 2;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public string $workspaceIdValue, public string $conversationId)
    {
        $this->onQueue('ai');
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    public function uniqueId(): string
    {
        return $this->conversationId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(UpdateLeadBrief $brief): void
    {
        $workspace = Workspace::query()->find($this->workspaceIdValue);
        $conversation = Conversation::query()->find($this->conversationId);
        if ($workspace === null || $conversation === null) {
            return;
        }
        $brief->fromConversation($workspace, $conversation);
    }
}
