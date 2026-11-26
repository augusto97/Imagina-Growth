<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sending;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Support\PlatformSending;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Jobs\SendMessageJob;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\ContactLinkToken;
use App\Domain\Messaging\Support\ConversationWindow;
use App\Domain\Messaging\Support\LinkRewriter;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Messaging\Support\SocialIdentity;
use App\Domain\Messaging\Support\WaTemplateText;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use App\Domain\Templates\Contracts\TemplateRenderer;
use App\Domain\Templates\Data\RenderContext;
use App\Domain\Templates\Data\RenderedMessage;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * El camino de todo envío (hub/CLAUDE.md §8): cuenta del canal → SendPolicy
 * (con reserva de frecuencia) → plantilla → enlaces rastreados → `messages` →
 * cola `messages:{canal}`. Un aplazamiento o una negación no crean mensaje; la
 * misma clave de idempotencia nunca envía dos veces.
 */
final class DefaultMessageDispatcher implements MessageDispatcher
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly SendPolicy $policy,
        private readonly DriverRegistry $drivers,
        private readonly MessageEvents $events,
    ) {}

    public function dispatch(OutboundMessage $message): DispatchResult
    {
        $workspace = $this->current->get();
        if ($workspace->id !== $message->workspaceId) {
            throw new InvalidArgumentException('El mensaje es de otro espacio de trabajo que el contexto actual.');
        }
        if ($message->idempotencyKey !== null) {
            $existing = Message::query()->where('idempotency_key', $message->idempotencyKey)->value('id');
            if (is_string($existing)) {
                return DispatchResult::duplicate($existing);
            }
        }
        // Cuenta suspendida por facturación (M21): solo se frena el marketing; el servicio y lo transaccional siguen.
        if ($message->kind->isCommercial() && ! app(Entitlements::class)->allows($workspace, 'marketing_sends')) {
            return DispatchResult::denied('billing_suspended');
        }
        $now = CarbonImmutable::now();
        if ($message->notBefore !== null && $message->notBefore->greaterThan($now)) {
            return DispatchResult::deferred($message->notBefore, 'not_before');
        }

        $contact = $message->contactId !== null ? Contact::query()->find($message->contactId) : null;
        if ($message->contactId !== null && $contact === null) {
            return DispatchResult::denied(PolicyDecision::NO_ADDRESS);
        }
        $account = $message->channelAccountId !== null
            ? ChannelAccount::query()->find($message->channelAccountId)
            : ChannelAccount::defaultFor($message->channel);
        if ($account === null || $account->channel !== $message->channel) {
            return DispatchResult::denied(PolicyDecision::CHANNEL_UNAVAILABLE);
        }
        // El correo por la cuenta de SES de la plataforma solo con un plan que lo incluya (S3).
        if ($account->provider === 'ses' && ! PlatformSending::emailAllowed($workspace)) {
            return DispatchResult::denied(PlatformSending::DENIED);
        }
        $to = $this->recipient($message, $contact, $workspace, $account);
        if ($to === null) {
            return DispatchResult::denied(PolicyDecision::NO_ADDRESS);
        }

        $request = new PolicyRequest($workspace, $contact, $message->channel, $message->kind, $message->sourceType, $account, $now, $message->isTest, $message->to !== null, $message->humanAgent);
        $decision = $this->policy->checkAndReserve($request);
        if ($decision->deferred()) {
            return DispatchResult::deferred($decision->until ?? $now->addHour(), (string) $decision->reason);
        }
        if ($decision->denied()) {
            return DispatchResult::denied((string) $decision->reason);
        }

        $id = strtoupper((string) Str::ulid());
        $publicId = Message::ID_PREFIX.'_'.$id;
        try {
            $rendered = $this->render($workspace, $message, $contact, $account, $publicId);
        } catch (Throwable $e) {
            // La plantilla no se pudo renderizar: el cupo de frecuencia vuelve al contacto.
            $this->policy->release($request, $now);
            report($e);

            return DispatchResult::denied('template_error');
        }
        try {
            $model = DB::transaction(function () use ($workspace, $message, $contact, $account, $rendered, $to, $id, $publicId, $now): Message {
                $links = new LinkRewriter($workspace->id, $id, $contact?->id);
                $marketing = $message->kind->isCommercial() && ! $message->isTest;
                $payload = [
                    'to' => $to,
                    'subject' => $rendered->subject,
                    'preheader' => $rendered->preheader,
                    'html' => $links->html($marketing && $message->channel === Channel::Email ? $this->complianceFooter($workspace, $rendered->html, $contact, $publicId) : $rendered->html),
                    'text' => $links->text($rendered->text),
                    'wa' => $rendered->waTemplateName !== null ? [
                        'name' => $rendered->waTemplateName,
                        'language' => $rendered->waLanguage ?? 'es',
                        'components' => $links->waComponents($rendered->waComponents),
                    ] : null,
                    'headers' => $marketing && $message->channel === Channel::Email && $contact !== null ? [
                        'List-Unsubscribe' => '<'.ContactLinkToken::unsubscribeUrl($workspace->id, $contact->id, 'email', $publicId).'>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ] : [],
                    'test' => $message->isTest,
                    'attempts' => 0,
                ];
                // Botones o lista de WhatsApp (mensaje de servicio, dentro de la ventana): `text` ya trae las opciones.
                if ($message->channel === Channel::WhatsApp && $message->templateId === null && WhatsAppInteractive::valid($message->context['interactive'] ?? null)) {
                    $payload['interactive'] = $message->context['interactive'];
                }
                // Messenger fuera de las 24 h: SendPolicy ya comprobó que lo escribe una persona y que no pasaron 7 días.
                if ($message->channel === Channel::Messenger && $message->humanAgent && $contact !== null
                    && ConversationWindow::forContact($workspace->id, $contact->id, $account, $now) === ConversationWindow::HUMAN_AGENT) {
                    $payload['messaging_tag'] = ConversationWindow::HUMAN_AGENT_TAG;
                }
                $country = strtoupper((string) ($contact?->getAttribute('country') ?: $workspace->country ?: 'CO'));
                $cost = $this->drivers->for($account->provider)->estimateCost($account, $country, $message->kind);

                $model = new Message;
                $model->forceFill([
                    'id' => $id,
                    'workspace_id' => $workspace->id,
                    'contact_id' => $contact?->id,
                    'channel_account_id' => $account->id,
                    'channel' => $message->channel,
                    'provider' => $account->provider,
                    'direction' => 'out',
                    'kind' => $message->kind,
                    'source_type' => $message->sourceType,
                    'source_id' => $message->sourceId,
                    'flow_id' => $message->flowId,
                    'campaign_id' => $message->campaignId,
                    'template_id' => $message->templateId,
                    'idempotency_key' => $message->idempotencyKey,
                    'status' => 'queued',
                    'cost_estimated' => $cost,
                    'cost_currency' => $cost !== null ? 'USD' : null,
                    'payload' => $payload,
                    'queued_at' => $now,
                ])->save();

                return $model;
            });
        } catch (UniqueConstraintViolationException) {
            // Otro proceso envió la misma clave al mismo tiempo: gana el primero.
            $this->policy->release($request, $now);
            $existing = Message::query()->where('idempotency_key', (string) $message->idempotencyKey)->value('id');

            return is_string($existing) ? DispatchResult::duplicate($existing) : DispatchResult::denied('duplicate');
        } catch (Throwable $e) {
            $this->policy->release($request, $now);

            throw $e;
        }

        SendMessageJob::dispatch($workspace->id, $model->id)->onQueue('messages:'.$message->channel->value)->afterCommit();
        $this->events->emit($workspace, $model, 'message.queued');

        return DispatchResult::queued($model->id);
    }

    /** Destino: el explícito (avisos y modo prueba), el correo o teléfono del contacto, o su PSID/IGSID en la página. */
    private function recipient(OutboundMessage $message, ?Contact $contact, Workspace $workspace, ChannelAccount $account): ?string
    {
        if ($message->channel->isSocial()) {
            $to = $message->to ?? ($contact !== null ? SocialIdentity::recipientFor($account, $contact->id) : null);

            return is_string($to) && preg_match('/^\d{3,64}$/', $to) === 1 ? $to : null;
        }
        $raw = $message->to ?? ($message->channel === Channel::Email ? $contact?->email : $contact?->phone);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        if ($message->channel === Channel::Email) {
            return filter_var(trim($raw), FILTER_VALIDATE_EMAIL) !== false ? trim($raw) : null;
        }

        return Phone::toE164($raw, strtoupper((string) ($contact?->getAttribute('country') ?: $workspace->country ?: 'CO')));
    }

    private function render(Workspace $workspace, OutboundMessage $message, ?Contact $contact, ChannelAccount $account, string $publicId): RenderedMessage
    {
        $links = $contact !== null ? [
            'baja' => ContactLinkToken::unsubscribeUrl($workspace->id, $contact->id, $message->channel->value, $publicId),
            'preferencias' => ContactLinkToken::preferencesUrl($workspace->id, $contact->id, $message->channel->value, $publicId),
        ] : [];
        if ($message->templateId === null) {
            // Avisos del sistema y respuestas libres: el contenido llega en el contexto.
            $text = isset($message->context['text']) ? (string) $message->context['text'] : null;
            $html = isset($message->context['html']) ? (string) $message->context['html'] : ($text !== null && $message->channel === Channel::Email ? nl2br(e($text)) : null);

            return new RenderedMessage(subject: isset($message->context['subject']) ? (string) $message->context['subject'] : null, html: $html, text: $text);
        }
        $vars = $message->context;
        $vars['enlaces'] = array_merge((array) ($vars['enlaces'] ?? []), $links);
        $rendered = app(TemplateRenderer::class)->render($message->templateId, new RenderContext($workspace, $contact, $vars));

        // WhatsApp no oficial (Zender, Imagina Whats) solo manda texto: la plantilla de WhatsApp se compone con sus parámetros.
        if ($account->isUnofficial() && $rendered->text === null && $rendered->waTemplateName !== null) {
            $tpl = WaTemplate::query()->where('name', $rendered->waTemplateName)->where('language', $rendered->waLanguage ?? 'es')->first();
            $text = $tpl !== null ? WaTemplateText::compose($tpl, $rendered->waComponents) : null;

            return new RenderedMessage(text: $text ?? throw new InvalidArgumentException('La plantilla de WhatsApp no está en este espacio de trabajo.'));
        }

        return $rendered;
    }

    /**
     * Pie obligatorio del correo de marketing (cumplimiento.md §8): quién envía,
     * dirección de contacto, por qué lo recibe y la baja. Si la plantilla ya trae
     * el enlace de baja, no se duplica.
     */
    private function complianceFooter(Workspace $workspace, ?string $html, ?Contact $contact, string $publicId): ?string
    {
        if ($html === null || $contact === null || str_contains($html, ContactLinkToken::base().'/u/')) {
            return $html;
        }
        $unsubscribe = ContactLinkToken::unsubscribeUrl($workspace->id, $contact->id, 'email', $publicId);
        $preferences = ContactLinkToken::preferencesUrl($workspace->id, $contact->id, 'email', $publicId);
        $address = $workspace->setting('business_address');
        $footer = '<div style="margin:24px auto 0;max-width:600px;padding:16px;font:12px/1.5 Arial,sans-serif;color:#6b7280;text-align:center">'
            .e(__('Recibes este correo porque aceptaste recibir novedades de :name.', ['name' => $workspace->name]))
            .(is_string($address) && $address !== '' ? '<br>'.e($address) : '')
            .'<br><a href="'.e($unsubscribe).'" style="color:#6b7280">'.e(__('Darme de baja')).'</a> · '
            .'<a href="'.e($preferences).'" style="color:#6b7280">'.e(__('Elegir qué recibir')).'</a></div>';

        return str_contains($html, '</body>') ? str_replace('</body>', $footer.'</body>', $html) : $html.$footer;
    }
}
