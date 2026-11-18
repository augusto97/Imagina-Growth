<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eventos message.* del hub (eventos.md §4.6, contrato v3). Un error al
 * escribir el evento nunca tumba el envío: se registra y se sigue.
 */
final class MessageEvents
{
    public function __construct(private readonly HubEvents $hubEvents) {}

    /** @param array<string, mixed> $extra */
    public function emit(Workspace $workspace, Message $message, string $event, array $extra = [], ?CarbonInterface $at = null): void
    {
        try {
            $this->hubEvents->emit($workspace, $event, $message->eventProperties() + array_filter($extra, fn ($v) => $v !== null), $message->contact_id, null, $at);
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.messaging.event_failed', ['event' => $event, 'message' => $message->id]);
        }
    }
}
