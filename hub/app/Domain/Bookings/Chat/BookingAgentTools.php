<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Chat;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Intelligence\Ai\Agent\Support\AgentRun;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Las herramientas del agente para agendar en el chat (M18, auditoría §5.1 función 4).
 * El agente **ofrece**; nunca agenda, mueve ni cancela por su cuenta: las opciones salen
 * como botones o lista de WhatsApp con su respuesta (dentro de la ventana de 24 h) y la
 * cita se agenda, se mueve o se cancela cuando el cliente toca una (`ChatBookingReply`).
 * Así el modelo no puede inventar un horario ni cancelar por error.
 *
 * Donde no se pueden mostrar opciones (Messenger, Instagram, WhatsApp no oficial) dan el
 * enlace de la página de reservas o de la cita. Sin el módulo `bookings` o sin servicios
 * activos, queda la herramienta de antes que ofrece pasar con una persona.
 */
final class BookingAgentTools
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ChatSlots $slots,
        private readonly DriverRegistry $drivers,
    ) {}

    /** @return list<AiTool> */
    public function for(AgentRun $run): array
    {
        if (! $this->entitlements->allows($run->workspace, 'bookings') || ! BookingService::query()->where('active', true)->exists()) {
            return [new AiTool('book_appointment', 'Agendar una cita (servicios).', self::schema(['when' => ['type' => ['string', 'null']]]),
                fn (array $in) => ['available' => false, 'message' => 'Este negocio no agenda citas por aquí; ofrece pasar con una persona.'])];
        }

        return [$this->services(), $this->offer($run), $this->mine($run), $this->reschedule($run), $this->cancel($run)];
    }

    private function services(): AiTool
    {
        return new AiTool('list_booking_services', 'Servicios que se pueden agendar, con su duración, precio y lugar.', self::schema([]),
            fn (array $in) => ['services' => BookingService::query()->where('active', true)->orderBy('name')->limit(20)->get()->map(fn (BookingService $s) => array_filter([
                'service_id' => $s->publicId(), 'name' => $s->name, 'duration_min' => $s->duration_min, 'price' => $s->price,
                'location' => __('booking.location.'.$s->location), 'description' => $s->description !== null ? mb_substr($s->description, 0, 300) : null,
            ], fn ($v) => $v !== null))->values()->all()]);
    }

    private function offer(AgentRun $run): AiTool
    {
        return new AiTool('offer_appointment_slots',
            'Busca horarios libres de un servicio y se los ofrece a la persona como opciones para tocar. Úsala cuando quiera agendar; date (AAAA-MM-DD) y time (HH:MM, 24 h) si dijo un día u hora.',
            self::schema([
                'service_id' => ['type' => 'string', 'description' => 'ID del servicio (de list_booking_services)'],
                'date' => ['type' => ['string', 'null'], 'description' => 'Día pedido, AAAA-MM-DD en la zona del negocio'],
                'part_of_day' => ['type' => ['string', 'null'], 'enum' => [...ChatSlots::PARTS, null], 'description' => 'morning, afternoon o evening si la pidió'],
                'time' => ['type' => ['string', 'null'], 'description' => 'Hora pedida, HH:MM en 24 h'],
            ]),
            function (array $in) use ($run): array {
                $service = $this->service($in['service_id'] ?? null);
                $starts = $this->slots->find($run->workspace, $service, self::str($in['date'] ?? null), self::part($in['part_of_day'] ?? null), self::str($in['time'] ?? null));
                $url = BookingSettings::pageUrl($run->workspace, $service->slug);
                if ($starts === []) {
                    return ['available' => false, 'message' => 'No hay horarios libres con eso. Ofrece otro día o el enlace para ver todos.', 'booking_url' => $url];
                }

                return $this->present($run, $starts, fn (CarbonImmutable $s) => ChatBookingIds::book($service->id, $s), $service, ['service' => $service->name, 'booking_url' => $url]);
            });
    }

    private function mine(AgentRun $run): AiTool
    {
        return new AiTool('my_appointments', 'Las próximas citas de la persona de este chat (solo las suyas).', self::schema([]),
            fn (array $in) => ['appointments' => $run->contact === null ? [] : Appointment::query()->where('contact_id', $run->contact->id)
                ->whereIn('status', Appointment::ACTIVE)->where('starts_at', '>', now())->orderBy('starts_at')->limit(5)->get()
                ->map(fn (Appointment $a) => [
                    'appointment_id' => $a->publicId(),
                    'service' => (string) BookingService::query()->whereKey($a->service_id)->value('name'),
                    'when' => ChatSlots::when($run->workspace, CarbonImmutable::instance($a->starts_at)),
                    'confirmed' => $a->status === 'confirmed',
                ])->values()->all()]);
    }

    private function reschedule(AgentRun $run): AiTool
    {
        return new AiTool('reschedule_appointment',
            'Ofrece horarios nuevos para mover una cita de la persona; se mueve cuando toque uno. date y part_of_day si pidió un día o momento.',
            self::schema([
                'appointment_id' => ['type' => 'string', 'description' => 'ID de la cita (de my_appointments)'],
                'date' => ['type' => ['string', 'null'], 'description' => 'Día pedido, AAAA-MM-DD'],
                'part_of_day' => ['type' => ['string', 'null'], 'enum' => [...ChatSlots::PARTS, null]],
            ]),
            function (array $in) use ($run): array {
                $appointment = $this->appointment($run, $in['appointment_id'] ?? null);
                $service = BookingService::query()->findOrFail($appointment->service_id);
                $starts = $this->slots->find($run->workspace, $service, self::str($in['date'] ?? null), self::part($in['part_of_day'] ?? null), moving: $appointment);
                $url = BookingSettings::manageUrl($run->workspace, (string) $appointment->manage_token).'?reprogramar=1';
                if ($starts === []) {
                    return ['available' => false, 'message' => 'No hay horarios libres con eso.', 'manage_url' => $url];
                }

                return $this->present($run, $starts, fn (CarbonImmutable $s) => ChatBookingIds::reschedule($appointment->id, $s), $service,
                    ['service' => $service->name, 'current' => ChatSlots::when($run->workspace, CarbonImmutable::instance($appointment->starts_at)), 'manage_url' => $url]);
            });
    }

    private function cancel(AgentRun $run): AiTool
    {
        return new AiTool('cancel_appointment',
            'La persona quiere cancelar una cita: le pide que lo confirme con un botón. Se cancela solo cuando toque «Sí, cancelar».',
            self::schema(['appointment_id' => ['type' => 'string', 'description' => 'ID de la cita (de my_appointments)']]),
            function (array $in) use ($run): array {
                $appointment = $this->appointment($run, $in['appointment_id'] ?? null);
                $when = ChatSlots::when($run->workspace, CarbonImmutable::instance($appointment->starts_at));
                $service = (string) BookingService::query()->whereKey($appointment->service_id)->value('name');
                if (! $this->canShowOptions($run)) {
                    return ['service' => $service, 'when' => $when, 'manage_url' => BookingSettings::manageUrl($run->workspace, (string) $appointment->manage_token),
                        'note' => 'Este chat no muestra botones: comparte manage_url para que la cancele ahí.'];
                }
                $run->interactive = WhatsAppInteractive::buttons(__('¿Confirmas que cancelamos tu cita?'), [
                    ['id' => ChatBookingIds::cancel($appointment->id), 'title' => __('Sí, cancelar')],
                    ['id' => ChatBookingIds::keep($appointment->id), 'title' => __('No, mantenerla')],
                ]);

                return ['service' => $service, 'when' => $when, 'delivery' => 'buttons',
                    'note' => 'Los botones «Sí, cancelar» y «No, mantenerla» van con tu respuesta: pregúntale si confirma, sin decir que ya se canceló.'];
            });
    }

    /**
     * @param  list<CarbonImmutable>  $starts
     * @param  callable(CarbonImmutable): string  $ids
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function present(AgentRun $run, array $starts, callable $ids, BookingService $service, array $extra): array
    {
        $labels = ChatSlots::labels($run->workspace, $starts);
        if (! $this->canShowOptions($run)) {
            return ['available' => true, 'options' => $labels, 'delivery' => 'link'] + $extra
                + ['note' => 'Este chat no muestra opciones para tocar: menciona dos o tres horarios y comparte el enlace para reservar.'];
        }
        $run->interactive = ChatSlots::interactive($run->workspace, $service, $starts, $ids, __('Elige el horario que prefieras:'));
        unset($extra['booking_url'], $extra['manage_url']);

        return ['available' => true, 'options' => $labels, 'delivery' => count($starts) <= WhatsAppInteractive::BUTTONS_MAX ? 'buttons' : 'list'] + $extra
            + ['note' => 'Las opciones van con tu respuesta para que la persona toque una: escribe una frase corta invitándola a elegir, sin repetir la lista ni inventar otros horarios. La cita queda cuando toque su opción.'];
    }

    /** Opciones para tocar: WhatsApp con un proveedor que muestra botones, en una conversación real. */
    private function canShowOptions(AgentRun $run): bool
    {
        if ($run->conversation === null || $run->isTest) {
            return false;
        }
        $account = ChannelAccount::query()->find($run->conversation->channel_account_id);

        return $account !== null && $account->channel === Channel::WhatsApp && (bool) ($this->drivers->for($account->provider)->capabilities()['buttons'] ?? false);
    }

    private function service(mixed $id): BookingService
    {
        $service = is_string($id) ? BookingService::findByPublicId($id) : null;
        if ($service === null) {
            $active = BookingService::query()->where('active', true)->limit(2)->get();
            $service = $active->count() === 1 ? $active->first() : null;
        }
        if ($service === null || ! $service->active) {
            throw new InvalidArgumentException('Ese servicio no existe: usa un service_id de list_booking_services.');
        }

        return $service;
    }

    private function appointment(AgentRun $run, mixed $id): Appointment
    {
        $appointment = is_string($id) && $run->contact !== null ? Appointment::findByPublicId($id) : null;
        if ($appointment === null || $appointment->contact_id !== $run->contact?->id || ! $appointment->isActive() || $appointment->starts_at->isPast()) {
            throw new InvalidArgumentException('Esa cita no es de esta persona o ya pasó: usa my_appointments.');
        }

        return $appointment;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /** @return 'any'|'morning'|'afternoon'|'evening' */
    private static function part(mixed $v): string
    {
        return match ($v) {
            'morning' => 'morning', 'afternoon' => 'afternoon', 'evening' => 'evening', default => 'any',
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function schema(array $properties): array
    {
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
