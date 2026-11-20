<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Assistant;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\ConversationWindow;
use App\Domain\Services\Leads\Models\LeadSettings;
use Carbon\CarbonImmutable;

/**
 * Por dónde se le puede escribir a un lead ahora mismo (M18, «Hoy» y el seguimiento):
 *
 * - `text`: hay una conversación con la ventana de 24 h abierta (WhatsApp, Messenger
 *   o Instagram): mensaje libre en esa conversación.
 * - `template`: WhatsApp con la ventana cerrada: solo con la plantilla de marketing
 *   aprobada para seguimientos (Ajustes de leads o la de `library_key` wa_lead_followup).
 * - `email`: sin WhatsApp posible, por correo.
 * - `none`: no hay por dónde (con el motivo).
 *
 * Solo dice el camino: el consentimiento, la ventana legal y la frecuencia los revisa
 * el despachador al enviar (SendPolicy). Corre con el espacio de trabajo activo.
 */
final class LeadReach
{
    public const TEMPLATE_KEY = 'wa_lead_followup';

    /**
     * @param  list<'whatsapp'|'email'>|null  $channels  canales permitidos, en orden (null = todos)
     * @param  bool  $marketing  un envío comercial (el seguimiento automático): Messenger e Instagram no lo permiten
     * @return array{mode: 'text'|'template'|'email'|'none', channel: string|null, conversation: Conversation|null, template_id: string|null, reason: string|null}
     */
    public static function for(string $workspaceId, ?Contact $contact, ?array $channels = null, ?CarbonImmutable $at = null, bool $marketing = false): array
    {
        $at ??= CarbonImmutable::now();
        $allowed = $channels ?? ['whatsapp', 'email'];
        if ($contact === null) {
            return self::none('no_contact');
        }
        $conversations = Conversation::query()->where('contact_id', $contact->id)->orderByDesc('last_message_at')->orderByDesc('updated_at')->limit(5)->get();
        $accounts = ChannelAccount::query()->whereIn('id', $conversations->pluck('channel_account_id')->all())->where('status', 'active')->get()->keyBy('id');
        $socialClosed = false;
        foreach ($conversations as $c) {
            $account = $accounts->get($c->channel_account_id);
            if (! $account instanceof ChannelAccount) {
                continue;
            }
            if (($account->channel === Channel::WhatsApp && ! in_array('whatsapp', $allowed, true)) || ($marketing && $account->channel->isSocial())) {
                continue;
            }
            $state = ConversationWindow::state($account->channel, $c->window_expires_at, $c->last_inbound_at, $at);
            if ($state === ConversationWindow::OPEN) {
                return ['mode' => 'text', 'channel' => $account->channel->value, 'conversation' => $c, 'template_id' => null, 'reason' => null];
            }
            $socialClosed = $socialClosed || $account->channel->isSocial();
        }
        $hasPhone = is_string($contact->phone_bidx) && $contact->phone_bidx !== '';
        if (in_array('whatsapp', $allowed, true) && $hasPhone && ChannelAccount::defaultFor(Channel::WhatsApp) !== null) {
            $configured = LeadSettings::for($workspaceId)->assistantRules()['whatsapp_template_id'];
            $template = TemplateRef::resolve($workspaceId, 'whatsapp', $configured, self::TEMPLATE_KEY);
            if ($template !== null) {
                $wa = $conversations->first(fn (Conversation $c) => ($accounts->get($c->channel_account_id)?->channel) === Channel::WhatsApp);

                return ['mode' => 'template', 'channel' => 'whatsapp', 'conversation' => $wa, 'template_id' => $template, 'reason' => null];
            }
        }
        $hasEmail = is_string($contact->email_bidx) && $contact->email_bidx !== '';
        if (in_array('email', $allowed, true) && $hasEmail && ChannelAccount::defaultFor(Channel::Email) !== null) {
            return ['mode' => 'email', 'channel' => 'email', 'conversation' => null, 'template_id' => null, 'reason' => null];
        }

        return self::none($socialClosed ? 'window_closed' : ($hasPhone ? 'no_template' : 'no_channel'));
    }

    /** @return array{mode: 'none', channel: null, conversation: null, template_id: null, reason: string} */
    private static function none(string $reason): array
    {
        return ['mode' => 'none', 'channel' => null, 'conversation' => null, 'template_id' => null, 'reason' => $reason];
    }
}
