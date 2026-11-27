<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Actions;

use App\Domain\Bookings\Http\Public\PublicBooking;
use App\Domain\Bookings\Models\Appointment;
use App\Domain\Bookings\Models\BookingService;
use App\Domain\Bookings\Support\AppointmentNoticeLog;
use App\Domain\Bookings\Support\BookingSettings;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Mensajes de la cita (M18): confirmación, recordatorios (24 h y 2 h), cambio y
 * cancelación. No son publicidad (cumplimiento.md §4.1): la confirmación, el cambio
 * y la cancelación son transaccionales y los recordatorios de utilidad; no piden
 * consentimiento de marketing ni esperan la ventana legal, pero sí respetan la
 * supresión. Por WhatsApp oficial solo con una plantilla de utilidad aprobada elegida en
 * los ajustes (fuera de la ventana de 24 h no hay texto libre); con un WhatsApp no oficial
 * (Imagina Whats, Zender), que no usa plantillas, sale como texto (`whatsAppText`) con
 * «Confirmo» o «Reprogramar» como respuesta escrita (sin botones; la atiende
 * `AppointmentReply`), con la supresión y los topes de esa conexión. Si no, por correo.
 * Clave de idempotencia: `appt:{cita}:{tipo}:{inicio}`, así un cambio de hora vuelve
 * a avisar. Con `whatsapp: false` (lo que se agenda en el chat, que ya confirma ahí
 * mismo) solo sale el correo.
 */
final class AppointmentNotifier
{
    public const KINDS = ['confirmation', 'reminder_24', 'reminder_2', 'rescheduled', 'cancelled'];

    public function __construct(private readonly MessageDispatcher $dispatcher, private readonly Branding $branding) {}

    /**
     * Un aviso que falla no deshace la cita: se reporta y sigue. Lo que salió (o por qué no) queda en `notice_log`
     * para la sección «Avisos» de la cita y la página de confirmación (lote 0.4.0, docs/avisos.md §4).
     */
    public function send(Workspace $workspace, Appointment $appointment, string $kind, bool $whatsapp = true): ?DispatchResult
    {
        try {
            $result = $this->deliver($workspace, $appointment, $kind, $whatsapp);
        } catch (Throwable $e) {
            report($e);
            $result = null;
        }
        app(AppointmentNoticeLog::class)->record($workspace, $appointment, $kind, self::key($appointment, $kind), $whatsapp, $result);

        return $result;
    }

    /** Clave de idempotencia del aviso (`appt:{cita}:{tipo}:{inicio}`): un cambio de hora vuelve a avisar. */
    public static function key(Appointment $appointment, string $kind): string
    {
        return 'appt:'.$appointment->id.':'.$kind.':'.CarbonImmutable::instance($appointment->starts_at)->getTimestamp();
    }

    private function deliver(Workspace $workspace, Appointment $appointment, string $kind, bool $whatsapp): ?DispatchResult
    {
        $contact = Contact::query()->find($appointment->contact_id);
        $service = BookingService::query()->find($appointment->service_id);
        if ($contact === null || $service === null) {
            return null;
        }
        $settings = BookingSettings::for($workspace);
        $messageKind = str_starts_with($kind, 'reminder') ? MessageKind::Utility : MessageKind::Transactional;
        $vars = self::vars($workspace, $appointment, $service);
        $key = self::key($appointment, $kind);
        $templateKey = str_starts_with($kind, 'reminder') ? 'reminder' : $kind;
        $waTemplate = ($settings['whatsapp_templates'][$templateKey] ?? null);
        $template = is_string($waTemplate) ? Template::findByPublicId($waTemplate) : null;

        $waAccount = $whatsapp && $contact->phone !== null ? ChannelAccount::defaultFor(Channel::WhatsApp) : null;
        if ($waAccount !== null && $waAccount->isUnofficial()) {
            // WhatsApp no oficial: no hay plantillas de Meta, el aviso va como texto (transaccional o de utilidad, nunca marketing).
            $result = $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id, contactId: $contact->id, channel: Channel::WhatsApp, kind: $messageKind,
                sourceType: 'system', sourceId: $appointment->publicId(), context: ['cita' => $vars, 'text' => self::whatsAppText($workspace, $kind, $vars, $service)],
                idempotencyKey: $key.':wa', channelAccountId: $waAccount->id,
            ));
            if ($result->sent()) {
                return $result;
            }
        } elseif ($whatsapp && $template !== null && $contact->phone !== null) {
            $result = $this->dispatcher->dispatch(new OutboundMessage(
                workspaceId: $workspace->id, contactId: $contact->id, channel: Channel::WhatsApp, kind: MessageKind::Utility,
                sourceType: 'system', sourceId: $appointment->publicId(), templateId: $template->id, context: ['cita' => $vars], idempotencyKey: $key.':wa',
            ));
            if ($result->sent()) {
                return $result;
            }
        }
        if ($contact->email === null) {
            return null;
        }
        [$subject, $text, $html] = $this->email($workspace, $kind, $vars);

        return $this->dispatcher->dispatch(new OutboundMessage(
            workspaceId: $workspace->id, contactId: $contact->id, channel: Channel::Email, kind: $messageKind, sourceType: 'system',
            sourceId: $appointment->publicId(), context: ['cita' => $vars, 'subject' => $subject, 'text' => $text, 'html' => $html],
            idempotencyKey: $key.':email',
        ));
    }

    /**
     * El aviso por un WhatsApp no oficial, en texto: sin botones, los recordatorios piden responder «Confirmo» o
     * «Reprogramar» (AppointmentReply entiende esas palabras).
     *
     * @param  array<string, string>  $v
     */
    public static function whatsAppText(Workspace $workspace, string $kind, array $v, BookingService $service): string
    {
        $business = (string) ($workspace->getAttribute('legal_name') ?: $workspace->name);
        $when = __(':fecha a las :hora (:lugar)', ['fecha' => $v['fecha'], 'hora' => $v['hora'], 'lugar' => $v['lugar']]);

        return match ($kind) {
            'confirmation' => __('¡Hola! Tu cita con :business quedó agendada: :servicio, el :when. Para verla, cambiarla o cancelarla: :url',
                ['business' => $business, 'servicio' => $v['servicio'], 'when' => $when, 'url' => $v['enlace']]),
            'reminder_24', 'reminder_2' => __('Te recordamos tu cita con :business: :servicio, el :when. Responde «Confirmo» para confirmarla o «Reprogramar» si necesitas otra hora.',
                ['business' => $business, 'servicio' => $v['servicio'], 'when' => $when]),
            'rescheduled' => __('Tu cita con :business cambió de hora: :servicio, el :when. Para verla o cambiarla: :url',
                ['business' => $business, 'servicio' => $v['servicio'], 'when' => $when, 'url' => $v['enlace']]),
            default => __('Cancelamos tu cita con :business: :servicio, el :when. Si quieres agendar otra: :url',
                ['business' => $business, 'servicio' => $v['servicio'], 'when' => $when, 'url' => BookingSettings::pageUrl($workspace, $service->slug)]),
        };
    }

    /** @return array<string, string> variables `cita.*` de las plantillas */
    public static function vars(Workspace $workspace, Appointment $appointment, BookingService $service): array
    {
        $tz = $workspace->timezone ?: 'America/Bogota';
        $start = PublicBooking::es(CarbonImmutable::instance($appointment->starts_at)->setTimezone($tz));

        return [
            'servicio' => $service->name,
            'fecha' => $start->isoFormat('dddd D [de] MMMM'),
            'hora' => $start->format('g:i a'),
            'duracion' => (string) $service->duration_min,
            'lugar' => __('booking.location.'.$service->location),
            'enlace' => BookingSettings::manageUrl($workspace, (string) $appointment->manage_token),
            'calendario' => BookingSettings::manageUrl($workspace, (string) $appointment->manage_token).'/calendario.ics',
        ];
    }

    /**
     * @param  array<string, string>  $v
     * @return array{0: string, 1: string, 2: string}
     */
    private function email(Workspace $workspace, string $kind, array $v): array
    {
        $brand = $this->branding->for($workspace);
        $business = (string) ($workspace->getAttribute('legal_name') ?: $workspace->name);
        [$subject, $lead] = match ($kind) {
            'confirmation' => [__('Tu cita quedó agendada: :servicio', ['servicio' => $v['servicio']]), __('Te esperamos.')],
            'reminder_24' => [__('Mañana es tu cita: :servicio', ['servicio' => $v['servicio']]), __('Te recordamos tu cita.')],
            'reminder_2' => [__('Tu cita es en 2 horas: :servicio', ['servicio' => $v['servicio']]), __('Te recordamos tu cita.')],
            'rescheduled' => [__('Tu cita cambió de hora: :servicio', ['servicio' => $v['servicio']]), __('Esta es la nueva hora.')],
            default => [__('Tu cita fue cancelada: :servicio', ['servicio' => $v['servicio']]), __('Cancelamos tu cita.')],
        };
        $when = __(':fecha a las :hora (:lugar)', ['fecha' => $v['fecha'], 'hora' => $v['hora'], 'lugar' => $v['lugar']]);
        $action = $kind === 'cancelled' ? __('Agendar otra cita') : __('Ver, reprogramar o cancelar');
        $text = $lead."\n\n".$v['servicio']."\n".$when."\n\n".$action.': '.$v['enlace']."\n\n".$business;
        $color = $brand->primaryColor;
        $html = '<div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#111827">'
            .'<p style="font-size:13px;color:#6B7280;margin:0 0 16px">'.e($business).'</p>'
            .'<h1 style="font-size:20px;margin:0 0 8px">'.e($lead).'</h1>'
            .'<p style="font-size:16px;font-weight:600;margin:16px 0 4px">'.e($v['servicio']).'</p>'
            .'<p style="font-size:15px;margin:0 0 20px">'.e($when).'</p>'
            .'<p style="margin:24px 0"><a href="'.e($v['enlace']).'" style="background:'.e($color).';color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600">'.e($action).'</a></p>'
            .($kind !== 'cancelled' ? '<p style="font-size:13px"><a href="'.e($v['calendario']).'">'.e(__('Agregar a mi calendario')).'</a></p>' : '')
            .'</div>';

        return [$subject, $text, $html];
    }
}
