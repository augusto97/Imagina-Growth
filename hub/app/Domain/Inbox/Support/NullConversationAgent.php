<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Support;

use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;

final class NullConversationAgent implements ConversationAgent
{
    public function onInbound(Conversation $conversation, Message $inbound): void {}

    public function suggest(Conversation $conversation): ?string
    {
        return null;
    }
}
