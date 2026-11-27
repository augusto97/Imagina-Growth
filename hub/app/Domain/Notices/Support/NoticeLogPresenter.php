<?php

declare(strict_types=1);

namespace App\Domain\Notices\Support;

use App\Domain\Bookings\Support\AppointmentNoticeLog;
use App\Domain\Messaging\Models\Message;
use App\Domain\Notices\Models\NoticeLog;
use Illuminate\Support\Collection;

/**
 * La sección «Avisos» de una cita o un lead (lote 0.4.0): cada renglón de `notice_log` con el estado vivo del mensaje
 * (si ya se entregó o falló después de encolarse) y su motivo. Los motivos los pone en palabras la SPA.
 */
final class NoticeLogPresenter
{
    /**
     * @param  Collection<int, NoticeLog>  $rows
     * @return list<array<string, mixed>>
     */
    public static function list(Collection $rows): array
    {
        $messages = Message::query()->whereIn('id', $rows->pluck('message_id')->filter()->unique()->values())
            ->get(['id', 'status', 'failed_reason', 'error_code', 'sent_at', 'delivered_at', 'read_at'])->keyBy('id');

        return array_values($rows->map(function (NoticeLog $row) use ($messages): array {
            $message = $row->message_id !== null ? $messages->get($row->message_id) : null;
            $status = $message !== null ? AppointmentNoticeLog::status((string) $message->status) : $row->status;

            return [
                'id' => $row->publicId(),
                'audience' => $row->audience,
                'event' => $row->event,
                'channel' => $row->channel,
                'recipient' => $row->recipient,
                'status' => $status,
                'reason' => $message !== null && $status === 'failed' ? ($message->error_code ?? 'failed') : $row->reason,
                'detail' => $message !== null && $status === 'failed' && is_string($message->failed_reason) ? mb_substr($message->failed_reason, 0, 200) : null,
                'message_status' => $message?->status,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        })->all());
    }
}
