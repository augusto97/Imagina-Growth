<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Llegó un mensaje de una persona (WhatsApp, más adelante Instagram y Messenger).
 * Lo emite `InboundHandler` después de guardar el mensaje (con su texto cifrado en
 * `messages.payload.text`). Lo escuchan la bandeja (no leídos, asignación, tiempo
 * real), el clasificador y el agente de IA. Las palabras de baja ya se procesaron.
 */
final class InboundMessageReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $messageId,
        public readonly ?string $conversationId,
        public readonly string $contactId,
        public readonly bool $optedOutByKeyword,
    ) {}
}
