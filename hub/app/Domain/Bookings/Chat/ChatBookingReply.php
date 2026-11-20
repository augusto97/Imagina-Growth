<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Chat;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Bookings\Actions\BookAppointment;
use App\Domain\Bookings\Actions\ChangeAppointment;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Bookings\Support\SlotTaken;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use Throwable;

/**
 * El cliente tocó una opción de la agenda en WhatsApp (M18, auditoría §5.1 función 4):
 * agenda, mueve o cancela la cita con las reglas de la agenda (BookAppointment y
 * ChangeAppointment: sin cruces, con el candado y la revisión dentro de la transacción,
 * en la zona del negocio) y le confirma en el chat. La cita queda en su lead (LeadIntake,
 * origen `booking`) y en Google Calendar si está conectado (en cola).
 *
 * Si el horario se ocupó mientras elegía, le ofrece los que siguen libres ese día. Tocar
 * dos veces la misma opción no agenda dos veces. La respuesta es de servicio: la persona
 * acaba de escribir, la ventana de 24 h está abierta. Esta respuesta no pasa por el modelo
 * (el agente no atiende estos toques). Corre en la cola `default`, fuera del webhook de Meta:
 * agendar crea el lead y lo puntúa, y el webhook debe responder rápido. Un reintento no
 * duplica nada (la cita ya agendada se reconoce y la respuesta lleva la clave del entrante).
 */
final class ChatBookingReply implements ShouldQueue
{
    public string $queue = 'default';

    /** Intentos del worker; explícitos para no depender del supervisor de Horizon (perfil pequeño). */
    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly BookAppointment $book,
        private readonly ChangeAppointment $change,
        private readonly ChatSlots $slots,
        private readonly MessageDispatcher $dispatcher,
        private readonly Entitlements $entitlements,
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
            $button = $message !== null ? (((array) $message->payload)['button_payload'] ?? null) : null;
            $choice = ChatBookingIds::parse(is_string($button) ? $button : null);
            if ($message === null || $choice === null) {
                return;
            }
            $conversation = $event->conversationId !== null ? Conversation::query()->find($event->conversationId) : null;
            try {
                $reply = match ($choice['action']) {
                    'book' => $this->bookSlot($workspace, $event->contactId, $choice['id'], $choice['start'] ?? CarbonImmutable::now()),
                    'reschedule' => $this->move($workspace, $event->contactId, $choice['id'], $choice['start'] ?? CarbonImmutable::now()),
                    'cancel' => $this->cancel($workspace, $event->contactId, $choice['id']),
                    'keep' => $this->keep($workspace, $event->contactId, $choice['id']),
                };
            } catch (Throwable $e) {
                report($e);
                $reply = ['text' => __('No pude completar eso. Una persona del equipo te ayuda en breve.')];
            }
            $this->reply($workspace, $event->contactId, $message, $conversation, $reply);
        });
    }

    /** @return array{text: string, interactive?: array<string, mixed>} */
    private function bookSlot(Workspace $workspace, string $contactId, string $serviceId, CarbonImmutable $start): array
    {
        $service = BookingService::query()->where('active', true)->find($serviceId);
        if ($service === null || ! $this->entitlements->allows($workspace, 'bookings')) {
            return ['text' => __('Ese servicio ya no se puede agendar por aquí. Una persona del equipo te ayuda en breve.')];
        }
        $same = Appointment::query()->where('contact_id', $contactId)->where('service_id', $service->id)->whereIn('status', Appointment::ACTIVE)
            ->where('starts_at', $start->utc())->first();
        if ($same !== null) {
            return ['text' => $this->confirmation($workspace, $service, $same, __('Ya tienes esa cita agendada.'))];
        }
        try {
            $appointment = $this->book->handle($workspace, $service, $start, null, $contactId, 'agent', source: ['channel' => 'whatsapp'],
                siteId: BookingSettings::site($workspace)?->id, confirmedInChat: true);
        } catch (SlotTaken) {
            return $this->taken($workspace, $service, $start, fn (CarbonImmutable $s) => ChatBookingIds::book($service->id, $s), null);
        }

        return ['text' => $this->confirmation($workspace, $service, $appointment, __('¡Listo! Tu cita quedó agendada.'))];
    }

    /** @return array{text: string, interactive?: array<string, mixed>} */
    private function move(Workspace $workspace, string $contactId, string $appointmentId, CarbonImmutable $start): array
    {
        $appointment = $this->own($contactId, $appointmentId);
        if ($appointment === null) {
            return ['text' => __('Esa cita ya no se puede cambiar. Si quieres, agendamos una nueva.')];
        }
        $service = BookingService::query()->findOrFail($appointment->service_id);
        if (CarbonImmutable::instance($appointment->starts_at)->equalTo($start)) {
            return ['text' => $this->confirmation($workspace, $service, $appointment, __('Tu cita ya está a esa hora.'))];
        }
        try {
            $this->change->reschedule($workspace, $appointment, $start, 'contact', confirmedInChat: true);
        } catch (SlotTaken) {
            return $this->taken($workspace, $service, $start, fn (CarbonImmutable $s) => ChatBookingIds::reschedule($appointment->id, $s), $appointment);
        } catch (InvalidArgumentException) {
            return ['text' => __('Esa cita ya no se puede cambiar. Si quieres, agendamos una nueva.')];
        }

        return ['text' => $this->confirmation($workspace, $service, $appointment->fresh() ?? $appointment, __('¡Listo! Movimos tu cita.'))];
    }

    /** @return array{text: string} */
    private function cancel(Workspace $workspace, string $contactId, string $appointmentId): array
    {
        $appointment = Appointment::query()->where('contact_id', $contactId)->find($appointmentId);
        if ($appointment === null) {
            return ['text' => __('No encontré esa cita.')];
        }
        if ($appointment->status === 'cancelled') {
            return ['text' => __('Esa cita ya estaba cancelada.')];
        }
        $service = BookingService::query()->find($appointment->service_id);
        try {
            $this->change->cancel($workspace, $appointment, 'contact', confirmedInChat: true);
        } catch (InvalidArgumentException) {
            return ['text' => __('Esa cita ya no se puede cancelar.')];
        }

        return ['text' => __('Listo, cancelamos tu cita de :service del :when. Si quieres agendar otra, escríbenos por aquí.', [
            'service' => $service !== null ? $service->name : __('tu servicio'),
            'when' => ChatSlots::when($workspace, CarbonImmutable::instance($appointment->starts_at)),
        ])];
    }

    /** @return array{text: string} */
    private function keep(Workspace $workspace, string $contactId, string $appointmentId): array
    {
        $appointment = $this->own($contactId, $appointmentId);
        $service = $appointment !== null ? BookingService::query()->find($appointment->service_id) : null;
        if ($appointment === null || $service === null) {
            return ['text' => __('No encontré esa cita.')];
        }

        return ['text' => $this->confirmation($workspace, $service, $appointment, __('Perfecto, tu cita sigue en pie.'))];
    }

    /**
     * El horario se ocupó mientras elegía: los que siguen libres ese día (o los próximos).
     *
     * @param  callable(CarbonImmutable): string  $ids
     * @return array{text: string, interactive?: array<string, mixed>}
     */
    private function taken(Workspace $workspace, BookingService $service, CarbonImmutable $start, callable $ids, ?Appointment $moving): array
    {
        $tz = ChatSlots::timezone($workspace);
        $starts = $this->slots->find($workspace, $service, $start->setTimezone($tz)->toDateString(), moving: $moving)
            ?: $this->slots->find($workspace, $service, moving: $moving);
        $intro = __('Ese horario se acaba de ocupar.');
        if ($starts === []) {
            return ['text' => $intro.' '.__('Elige otro aquí: :url', ['url' => BookingSettings::pageUrl($workspace, $service->slug)])];
        }
        $interactive = ChatSlots::interactive($workspace, $service, $starts, $ids, $intro.' '.__('Estos siguen libres:'));

        return ['text' => WhatsAppInteractive::asText($interactive), 'interactive' => $interactive];
    }

    private function confirmation(Workspace $workspace, BookingService $service, Appointment $appointment, string $lead): string
    {
        return $lead.' '.__(':service, el :when (:place). Si necesitas cambiarla o cancelarla, escríbenos por aquí o entra a :url', [
            'service' => $service->name,
            'when' => ChatSlots::when($workspace, CarbonImmutable::instance($appointment->starts_at)),
            'place' => __('booking.location.'.$service->location),
            'url' => BookingSettings::manageUrl($workspace, (string) $appointment->manage_token),
        ]);
    }

    private function own(string $contactId, string $appointmentId): ?Appointment
    {
        $appointment = Appointment::query()->where('contact_id', $contactId)->find($appointmentId);

        return $appointment !== null && $appointment->isActive() && $appointment->starts_at->isFuture() ? $appointment : null;
    }

    /** @param array{text: string, interactive?: array<string, mixed>} $reply */
    private function reply(Workspace $workspace, string $contactId, Message $inbound, ?Conversation $conversation, array $reply): void
    {
        $result = $this->dispatcher->dispatch(new OutboundMessage(
            workspaceId: $workspace->id, contactId: $contactId, channel: Channel::WhatsApp, kind: MessageKind::Service, sourceType: 'inbox',
            sourceId: $conversation?->id, context: array_filter(['text' => $reply['text'], 'interactive' => $reply['interactive'] ?? null]),
            idempotencyKey: 'bk:'.$inbound->id, channelAccountId: $conversation->channel_account_id ?? $inbound->channel_account_id,
        ));
        if ($conversation !== null && $result->sent() && $result->messageId !== null) {
            Message::query()->whereKey($result->messageId)->update(['conversation_id' => $conversation->id]);
            $conversation->forceFill(['last_outbound_at' => now()])->save();
        }
    }
}
