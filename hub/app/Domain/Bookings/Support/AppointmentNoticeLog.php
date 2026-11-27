<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Support;

use App\Domain\Bookings\Models\Appointment;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Notices\Models\NoticeLog;
use App\Domain\Notices\Support\NoticeLogger;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\Pii;

/**
 * Qué se le avisó al cliente de una cita y, si no salió, por qué (lote 0.4.0, docs/avisos.md §4). Lo llama
 * `AppointmentNotifier::send` después de intentar: mira los mensajes que quedaron con la clave del aviso (`…:wa`,
 * `…:email`) y, si no quedó ninguno, deja el motivo en palabras de máquina (`no_phone`, `no_whatsapp_channel`,
 * `no_whatsapp_template`, `no_email`, o el que dio el despachador: `channel_unavailable`, `plan_no_platform_email`…).
 */
final class AppointmentNoticeLog
{
    public function record(Workspace $workspace, Appointment $appointment, string $kind, string $key, bool $whatsapp, ?DispatchResult $result): void
    {
        $messages = Message::query()->whereIn('idempotency_key', [$key.':wa', $key.':email'])->get(['id', 'idempotency_key', 'channel', 'status']);
        if ($messages->isNotEmpty() && NoticeLog::query()->where('subject_type', 'appointment')->where('subject_id', $appointment->id)
            ->where('event', $kind)->whereIn('message_id', $messages->pluck('id'))->exists()) {
            return; // El mismo aviso otra vez (un reintento): ya está.
        }
        $contact = Contact::query()->find($appointment->contact_id);
        $phone = is_string($contact?->phone) && $contact->phone !== '' ? Pii::maskPhone($contact->phone) : null;
        $email = is_string($contact?->email) && $contact->email !== '' ? Pii::maskEmail($contact->email) : null;
        $log = fn (?string $channel, string $status, ?string $reason, ?string $messageId, ?string $to) => NoticeLogger::record(
            $workspace->id, 'appointment', $appointment->id, 'customer', $kind, $channel, $status, $reason, $messageId, $to);

        $wa = $messages->firstWhere('idempotency_key', $key.':wa');
        $mail = $messages->firstWhere('idempotency_key', $key.':email');
        if ($wa !== null) {
            $log('whatsapp', self::status((string) $wa->status), null, (string) $wa->id, $phone);
        }
        if ($mail !== null) {
            $log('email', self::status((string) $mail->status), null, (string) $mail->id, $email);
        }
        if ($wa !== null || $mail !== null) {
            return;
        }
        // No salió por ningún lado: un renglón por canal con el motivo.
        if (! $whatsapp) {
            $log('whatsapp', 'sent', 'in_chat', null, $phone);
        } else {
            $log('whatsapp', 'skipped', self::whatsappReason($workspace, $contact, $kind), null, $phone);
        }
        if ($contact === null || $email === null) {
            $log('email', 'skipped', 'no_email', null, null);
        } elseif ($result !== null && $result->status !== DispatchResult::QUEUED && $result->status !== DispatchResult::DUPLICATE) {
            $log('email', $result->status === DispatchResult::DEFERRED ? 'deferred' : 'denied', $result->reason, null, $email);
        } else {
            $log('email', 'skipped', 'not_sent', null, $email);
        }
    }

    /** Por qué el WhatsApp de la cita no salió, según cómo está el espacio de trabajo. */
    public static function whatsappReason(Workspace $workspace, ?Contact $contact, string $kind): string
    {
        if ($contact === null || ! is_string($contact->phone) || $contact->phone === '') {
            return 'no_phone';
        }
        $account = ChannelAccount::defaultFor(Channel::WhatsApp);
        if ($account === null) {
            return 'no_whatsapp_channel';
        }
        $templates = (array) (BookingSettings::for($workspace)['whatsapp_templates'] ?? []);
        $templateKey = str_starts_with($kind, 'reminder') ? 'reminder' : $kind;
        if (! $account->isUnofficial() && ! is_string($templates[$templateKey] ?? null)) {
            return 'no_whatsapp_template';
        }

        return 'whatsapp_not_sent';
    }

    /** El estado del aviso a partir del mensaje (en cola, enviado, entregado, falló…). */
    public static function status(string $messageStatus): string
    {
        return match ($messageStatus) {
            'sent', 'delivered', 'read' => 'sent',
            'failed', 'bounced' => 'failed',
            'deferred' => 'deferred',
            'denied', 'skipped' => 'denied',
            default => 'queued',
        };
    }

    /**
     * El canal por el que de verdad salió (o está en cola) el último aviso al cliente de la cita, para la página de
     * confirmación: `whatsapp`, `email` o null si no salió nada.
     *
     * @param  list<string>  $events
     */
    public static function deliveredVia(Appointment $appointment, array $events): ?string
    {
        $row = NoticeLog::query()->where('subject_type', 'appointment')->where('subject_id', $appointment->id)->where('audience', 'customer')
            ->whereIn('event', $events)->whereIn('status', ['queued', 'sent'])->whereIn('channel', ['whatsapp', 'email'])
            ->where(fn ($q) => $q->whereNull('reason')->orWhere('reason', '!=', 'in_chat'))
            ->orderByDesc('created_at')->first(['channel']);

        return $row?->channel;
    }
}
