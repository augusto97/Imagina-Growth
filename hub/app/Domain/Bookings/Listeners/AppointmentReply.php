<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Listeners;

use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Str;

/**
 * Respuestas al recordatorio de la cita por WhatsApp (M18): «Confirmo» (texto o
 * botón de respuesta rápida con el payload `appt_confirm`) confirma la próxima
 * cita del contacto en las siguientes 48 horas; «Reprogramar» (`appt_reschedule`)
 * le responde con el enlace para elegir otro horario. La respuesta va como mensaje
 * de servicio: la persona acaba de escribir, la ventana de 24 h está abierta.
 */
final class AppointmentReply
{
    private const CONFIRM = ['confirmo', 'confirmar', 'si confirmo', 'confirmado', 'confirmada', 'si, confirmo', 'sí confirmo', 'sí, confirmo'];

    private const RESCHEDULE = ['reprogramar', 'reagendar', 'cambiar hora', 'cambiar la hora', 'cambiar cita'];

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly ChangeAppointment $change,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function handle(InboundMessageReceived $event): void
    {
        if ($event->optedOutByKeyword) {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, function () use ($workspace, $event): void {
            $message = Message::query()->find($event->messageId);
            if ($message === null) {
                return;
            }
            $payload = (array) $message->payload;
            $button = (string) ($payload['button_payload'] ?? '');
            $text = trim(Str::lower(Str::ascii((string) ($payload['text'] ?? ''))), " \t\n\r\0\x0B.!¡✅👍");
            $intent = match (true) {
                $button === 'appt_confirm', in_array($text, array_map(fn ($w) => Str::ascii($w), self::CONFIRM), true) => 'confirm',
                $button === 'appt_reschedule', in_array($text, self::RESCHEDULE, true) => 'reschedule',
                default => null,
            };
            if ($intent === null) {
                return;
            }
            $appointment = Appointment::query()->where('contact_id', $event->contactId)->whereIn('status', Appointment::ACTIVE)
                ->where('starts_at', '>', now())->where('starts_at', '<=', now()->addHours(48))->orderBy('starts_at')->first();
            if ($appointment === null) {
                return;
            }
            if ($intent === 'confirm') {
                $this->change->mark($workspace, $appointment, 'confirmed', 'contact');
                $reply = __('¡Gracias! Tu cita quedó confirmada. Te esperamos.');
            } else {
                $reply = __('Claro. Elige el nuevo horario aquí: :url', ['url' => BookingSettings::manageUrl($workspace, (string) $appointment->manage_token).'?reprogramar=1']);
            }
            $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id, contactId: $event->contactId, channel: Channel::WhatsApp, kind: MessageKind::Service,
                sourceType: 'system', sourceId: $appointment->publicId(), context: ['text' => $reply],
                idempotencyKey: 'appt:'.$appointment->id.':reply:'.$event->messageId,
            ));
        });
    }
}
