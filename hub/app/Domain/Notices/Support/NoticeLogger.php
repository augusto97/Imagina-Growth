<?php

declare(strict_types=1);

namespace App\Domain\Notices\Support;

use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Notices\Models\NoticeLog;
use Throwable;

/**
 * Deja en `notice_log` qué se avisó por una cita o un lead (lote 0.4.0, docs/avisos.md §4). Nunca rompe el aviso: si
 * no se puede guardar, se reporta y sigue.
 */
final class NoticeLogger
{
    public static function record(string $workspaceId, string $subjectType, string $subjectId, string $audience, string $event, ?string $channel,
        string $status, ?string $reason = null, ?string $messageId = null, ?string $recipient = null, ?string $userId = null): void
    {
        try {
            NoticeLog::query()->create([
                'workspace_id' => $workspaceId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'audience' => $audience,
                'event' => mb_substr($event, 0, 32), 'channel' => $channel, 'status' => $status, 'reason' => $reason !== null ? mb_substr($reason, 0, 64) : null,
                'message_id' => $messageId, 'recipient' => $recipient !== null ? mb_substr($recipient, 0, 190) : null, 'user_id' => $userId,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** El estado del log según lo que respondió el despachador (`duplicate` no se registra: ya está). */
    public static function status(DispatchResult $result): ?string
    {
        return match ($result->status) {
            DispatchResult::QUEUED => 'queued',
            DispatchResult::DEFERRED => 'deferred',
            DispatchResult::DENIED => 'denied',
            default => null,
        };
    }
}
