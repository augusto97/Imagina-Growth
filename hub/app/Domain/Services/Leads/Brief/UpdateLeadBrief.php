<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Brief;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;

/**
 * «La ficha se completa sola» (M18): con lo que el cliente escribe en la bandeja
 * (WhatsApp, Messenger, Instagram) la IA llena la ficha del lead abierto del
 * contacto, y la ciudad del contacto si no la tenía. Si no hay lead y el cliente
 * quiere comprar por WhatsApp, lo crea con la misma regla de los leads de WhatsApp
 * (origen `whatsapp`, referencia = la conversación; se apaga en Ajustes).
 *
 * Nunca pisa lo que editó una persona (LeadBrief::merge) y solo guarda valores que
 * aparecen en lo que escribió el cliente. Corre en la cola `ai` con el espacio de
 * trabajo activo; la historia del lead guarda qué campos cambió, no los valores.
 */
final class UpdateLeadBrief
{
    /** Mensajes de la conversación que se leen (los más recientes, de los últimos 30 días). */
    public const HISTORY = 30;

    public function __construct(
        private readonly BriefExtractor $extractor,
        private readonly LeadIntake $intake,
        private readonly Entitlements $entitlements,
    ) {}

    /** @return list<string> los campos que cambiaron */
    public function fromConversation(Workspace $workspace, Conversation $conversation): array
    {
        $settings = LeadSettings::for($workspace->id);
        if (! $settings->ai_brief || ! $this->entitlements->allows($workspace, 'services')) {
            return [];
        }
        $channel = ChannelAccount::query()->find($conversation->channel_account_id)->channel ?? null;
        if (! $channel instanceof Channel || ! $channel->isConversational()) {
            return [];
        }
        [$transcript, $lastInbound] = $this->transcript($conversation);
        if ($lastInbound === null) {
            return [];
        }
        $lead = self::openLead($conversation->contact_id);
        // Sin lead solo se puede crear uno por WhatsApp, y no de una conversación de soporte, queja o baja:
        // ahí no vale la pena llamar al modelo.
        if ($lead === null && ($channel !== Channel::WhatsApp || ! $settings->whatsapp_leads || in_array($conversation->intent, ['support', 'complaint', 'optout'], true))) {
            return [];
        }
        $result = $this->extractor->extract($workspace->id, $transcript, $workspace->name);
        if ($result === null) {
            return [];
        }
        if ($lead === null) {
            $wantsToBuy = $result['purchase'] || $conversation->intent === 'purchase';
            if (! $wantsToBuy) {
                return [];
            }
            $id = $this->intake->capture(new LeadIntakeInput(
                workspaceId: $workspace->id, contactId: $conversation->contact_id, origin: 'whatsapp', originRef: $conversation->id, source: ['channel' => 'whatsapp'],
            ));
            $lead = $id !== null ? Lead::query()->find($id) : null;
            if ($lead === null || ! $lead->isOpen()) {
                return [];
            }
        }
        $found = [];
        foreach ($result['fields'] as $key => $item) {
            $found[$key] = array_filter(['value' => $item['value'], 'quote' => $item['quote'], 'reason' => $item['reason'] ?? null], fn ($v) => $v !== null);
        }
        /** @var array<string, array{value: string, quote: string, reason?: string}> $found */
        [$brief, $changed] = LeadBrief::merge($lead->brief, $found, now()->toIso8601String(), PrefixedId::encode(Message::ID_PREFIX, $lastInbound->id));
        if ($changed === []) {
            return [];
        }
        $lead->brief = $brief;
        $lead->brief_updated_at = now();
        if ($lead->service === null && in_array('need', $changed, true)) {
            $lead->service = mb_substr((string) LeadBrief::value($brief, 'need'), 0, 160);
        }
        $lead->save();
        LeadActivity::log($lead, 'brief', 'ai', ['fields' => $changed, 'discarded' => $result['discarded']]);
        $city = LeadBrief::value($brief, 'city');
        if ($city !== null && in_array('city', $changed, true)) {
            $contact = Contact::query()->find($lead->contact_id);
            $current = $contact?->getAttribute('city');
            if ($contact !== null && (! is_string($current) || trim($current) === '')) {
                $contact->forceFill(['city' => mb_substr($city, 0, 80)])->save();
            }
        }

        return $changed;
    }

    /** @return Lead|null el lead abierto más reciente del contacto */
    public static function openLead(string $contactId): ?Lead
    {
        return Lead::query()->where('contact_id', $contactId)->whereNull('won_at')->whereNull('lost_at')->orderByDesc('created_at')->first();
    }

    /**
     * La conversación en texto, del mensaje más viejo al más nuevo, y el último entrante.
     *
     * @return array{0: list<array{role: 'customer'|'business', text: string}>, 1: Message|null}
     */
    public function transcript(Conversation $conversation): array
    {
        $rows = Message::query()->where('conversation_id', $conversation->id)->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::HISTORY)->get()->reverse()->values();
        $out = [];
        $last = null;
        foreach ($rows as $m) {
            $text = trim((string) (((array) $m->payload)['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $inbound = $m->direction === 'in';
            $out[] = ['role' => $inbound ? 'customer' : 'business', 'text' => $text];
            if ($inbound) {
                $last = $m;
            }
        }

        return [$out, $last];
    }
}
