<?php

declare(strict_types=1);

namespace App\Domain\Capture\Listeners;

use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Models\WhatsAppLinkHit;
use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Inbound\InboundHandler;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Chats de WhatsApp con atribución (rediseño v2 §11.3): cuando llega un mensaje con «(ref: CÓDIGO)» de un QR impreso
 * o del botón de una página de captura (el mismo formato del widget, eventos.md §6.3):
 *
 * - Registra el chat (`whatsapp_link_hits`, uno por mensaje) y deja el origen del contacto si no tenía
 *   (`first_source` con UTM `qr` para el QR impreso).
 * - Crea el lead (origen `whatsapp`, la fuente con `utm_source = qr` o `landing`) por `LeadIntake`, con el puntaje, el
 *   vendedor y el aviso de siempre, si el enlace lo pide (sí por defecto).
 * - Si el enlace lo pide y la persona no tiene consentimiento de marketing por WhatsApp, le pregunta con dos botones
 *   si quiere recibir novedades, con el texto legal vigente (cumplimiento.md §3.1: la persona inició la conversación y
 *   acepta por un botón). Es un mensaje de servicio dentro de la ventana de 24 h que ella acaba de abrir.
 * - El toque «Sí, quiero» registra el consentimiento con su prueba (mensaje, botón, texto y versión) y confirma; «No,
 *   gracias» solo responde. El mensaje prellenado por sí solo nunca es consentimiento.
 *
 * Corre en la cola `default`, fuera del webhook de Meta; un reintento no duplica nada (el chat es único por mensaje y
 * las respuestas llevan la clave del entrante).
 */
final class WhatsAppLinkInbound implements ShouldQueue
{
    public const OPT_IN = 'igr_optin:';

    public string $queue = 'default';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly LeadIntake $leads,
        private readonly MessageDispatcher $dispatcher,
        private readonly RecordConsent $consents,
        private readonly ConsentTexts $texts,
        private readonly DriverRegistry $drivers,
    ) {}

    public static function isOptInButton(mixed $payload): bool
    {
        return is_string($payload) && str_starts_with($payload, self::OPT_IN);
    }

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
            if ($message === null || $message->direction !== 'in' || $message->channel !== Channel::WhatsApp) {
                return;
            }
            $payload = (array) $message->payload;
            if (self::isOptInButton($payload['button_payload'] ?? null)) {
                $this->answer($workspace, $event, $message, (string) $payload['button_payload']);

                return;
            }
            $code = InboundHandler::waRef(is_string($payload['text'] ?? null) ? $payload['text'] : null);
            if ($code === null || strlen($code) !== 7) {
                return;
            }
            $link = WhatsAppLink::query()->where('code', $code)->where('status', 'active')->first();
            if ($link !== null) {
                $this->attribute($workspace, $event, $message, $link, (string) ($payload['text'] ?? ''));
            }
        });
    }

    private function attribute(Workspace $workspace, InboundMessageReceived $event, Message $message, WhatsAppLink $link, string $text): void
    {
        $contact = Contact::query()->find($event->contactId);
        if ($contact === null) {
            return;
        }
        $at = CarbonImmutable::instance($message->created_at ?? now());
        $hit = WhatsAppLinkHit::query()->firstOrCreate(['link_id' => $link->id, 'message_id' => $message->id], [
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'was_new' => $contact->created_at !== null && $contact->created_at->greaterThanOrEqualTo($at->subMinutes(2)),
            'created_at' => now(),
        ]);
        if (! $hit->wasRecentlyCreated) {
            return; // reintento del job
        }
        $page = $link->page_id !== null ? LandingPage::query()->find($link->page_id) : null;
        $campaign = $page !== null ? $page->slug : strtolower($link->code);
        $source = array_filter([
            'channel' => 'whatsapp',
            'utm_source' => $link->kind === 'qr' ? 'qr' : 'landing',
            'utm_medium' => $link->kind === 'qr' ? 'qr' : 'whatsapp',
            'utm_campaign' => $campaign,
            'page' => $page !== null ? '/p/'.$page->slug : null,
            'wa_link' => $link->publicId(),
            'via' => $link->kind === 'qr' ? 'qr' : 'link',
        ]);
        if ($contact->getAttribute('first_source') === null) {
            $contact->forceFill(['first_source' => ['channel' => 'whatsapp', 'utm_source' => $source['utm_source'], 'utm_medium' => $source['utm_medium'],
                'utm_campaign' => $campaign, 'at' => $at->toIso8601String(), 'via' => 'whatsapp_link']])->save();
        }
        if ($link->createsLead()) {
            try {
                $leadId = $this->leads->capture(new LeadIntakeInput(
                    workspaceId: $workspace->id, contactId: $contact->id, origin: 'whatsapp', originRef: 'wl:'.$hit->id,
                    service: is_string($link->setting('service')) ? $link->setting('service') : null, source: $source,
                    message: trim((string) preg_replace('/\(?\bref:\s*[A-Za-z0-9]{4,12}\)?/i', '', $text)) ?: null,
                ));
                if ($leadId !== null) {
                    $hit->forceFill(['lead_id' => $leadId])->save();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        if ($link->asksConsent() && ! $this->hasWhatsAppConsent($contact->id)) {
            $this->ask($workspace, $event, $message, $hit);
        }
    }

    private function hasWhatsAppConsent(string $contactId): bool
    {
        $consent = ContactConsent::query()->where('contact_id', $contactId)->where('channel', 'whatsapp')->where('purpose', 'marketing')->first();
        if ($consent === null) {
            return false;
        }

        // Ya aceptó, o se dio de baja hace poco: no se le vuelve a preguntar.
        return $consent->status === 'granted' || ($consent->revoked_at !== null && $consent->revoked_at->greaterThan(now()->subDays(90)));
    }

    private function ask(Workspace $workspace, InboundMessageReceived $event, Message $inbound, WhatsAppLinkHit $hit): void
    {
        $this->texts->current($workspace); // crea la v1 del texto si el espacio de trabajo no tenía
        $label = (string) $this->texts->latest($workspace, 'whatsapp')?->body;
        $business = BusinessProfile::for($workspace)['name'] ?: $workspace->name;
        // Sin botones (WhatsApp no oficial) las opciones salen numeradas: aceptar es responder «1» o «Sí, quiero».
        $account = ChannelAccount::query()->find($inbound->channel_account_id);
        $buttons = $account !== null && $this->drivers->capabilitiesOf($account)->buttons;
        $body = $buttons
            ? __('¿Quieres recibir ofertas y novedades de :business por aquí? Al tocar «Sí, quiero» aceptas: «:text»', ['business' => $business, 'text' => $label])
            : __('¿Quieres recibir ofertas y novedades de :business por aquí? Al responder «1» o «Sí, quiero» aceptas: «:text»', ['business' => $business, 'text' => $label]);
        $interactive = WhatsAppInteractive::buttons($body, [
            ['id' => self::OPT_IN.'y:'.$hit->id, 'title' => __('Sí, quiero')],
            ['id' => self::OPT_IN.'n:'.$hit->id, 'title' => __('No, gracias')],
        ]);
        if ($this->reply($workspace, $event, $inbound, ['text' => WhatsAppInteractive::asText($interactive), 'interactive' => $interactive], 'wl-optin:')) {
            $hit->forceFill(['consent_asked_at' => now()])->save();
        }
    }

    /** El toque a «Sí, quiero» o «No, gracias». */
    private function answer(Workspace $workspace, InboundMessageReceived $event, Message $message, string $payload): void
    {
        if (preg_match('/^'.preg_quote(self::OPT_IN, '/').'([yn]):(\d{1,18})$/', $payload, $m) !== 1) {
            return;
        }
        $hit = WhatsAppLinkHit::query()->find((int) $m[2]);
        // Solo vale el toque de la misma persona a la que se le preguntó (o del contacto en el que se fusionó).
        $merged = $hit !== null ? Contact::query()->find($hit->contact_id)?->getAttribute('merged_into_id') : null;
        if ($hit === null || ! in_array($event->contactId, array_filter([$hit->contact_id, is_string($merged) ? $merged : null]), true)) {
            return;
        }
        if ($m[1] === 'n') {
            $this->reply($workspace, $event, $message, ['text' => __('Entendido. Solo te escribiremos para responder lo que nos preguntes.')], 'wl-optin-answer:');

            return;
        }
        $legal = $this->texts->latest($workspace, 'whatsapp');
        $at = CarbonImmutable::instance($message->created_at ?? now());
        $link = WhatsAppLink::query()->find($hit->link_id);
        // Con botones, el toque; sin botones, la respuesta escrita («1», «Sí, quiero»), que queda como tal en la evidencia.
        $written = (bool) (((array) $message->payload)['option_reply'] ?? false);
        $this->consents->handle($workspace, $event->contactId, 'whatsapp', 'marketing', 'granted', $written ? 'whatsapp_reply' : 'whatsapp_button', $legal?->version, $at, array_filter([
            'message_id' => $message->publicId(),
            'button_payload' => mb_substr($payload, 0, 64),
            'reply' => $written ? 'numbered_option' : null,
            'prompt' => 'whatsapp_link',
            'wa_link' => $link?->publicId(),
            'legal_text' => $legal?->body,
        ]), authoritative: true);
        $hit->forceFill(['consent_granted_at' => $at])->save();
        $this->reply($workspace, $event, $message, ['text' => __('¡Listo! Te enviaremos novedades por aquí. Para dejar de recibirlas, responde «baja» cuando quieras.')], 'wl-optin-answer:');
    }

    /** @param array{text: string, interactive?: array<string, mixed>} $reply */
    private function reply(Workspace $workspace, InboundMessageReceived $event, Message $inbound, array $reply, string $prefix): bool
    {
        $conversation = $event->conversationId !== null ? Conversation::query()->find($event->conversationId) : null;
        $result = $this->dispatcher->dispatch(new OutboundMessage(
            workspaceId: $workspace->id, contactId: $event->contactId, channel: Channel::WhatsApp, kind: MessageKind::Service, sourceType: 'inbox',
            sourceId: $conversation?->id, context: array_filter(['text' => $reply['text'], 'interactive' => $reply['interactive'] ?? null]),
            idempotencyKey: $prefix.$inbound->id, channelAccountId: $conversation->channel_account_id ?? $inbound->channel_account_id,
        ));
        if ($conversation !== null && $result->sent() && $result->messageId !== null) {
            Message::query()->whereKey($result->messageId)->update(['conversation_id' => $conversation->id]);
            $conversation->forceFill(['last_outbound_at' => now()])->save();
        }

        return $result->sent();
    }
}
