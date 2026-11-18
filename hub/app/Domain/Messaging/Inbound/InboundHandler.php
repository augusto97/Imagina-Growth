<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Inbound;

use App\Analytics\Ch;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Messaging\Channels\Data\InboundMessage;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Sending\UnsubscribeService;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Mensaje entrante de WhatsApp o SMS (hub/CLAUDE.md §8):
 *
 * - Busca al contacto por el teléfono (índice ciego) o lo crea.
 * - Abre la ventana de 24 h de la conversación.
 * - Marca como respondido el mensaje al que contesta, con el botón tocado
 *   («Confirmo», «Cancelar»), para que los flujos lo puedan esperar.
 * - Si trae el token del sitio (`ref: K7Q2`, eventos.md §6.3), vincula el número
 *   con el visitante anónimo del clic.
 * - Palabras de baja: revoca el marketing del canal y responde con una confirmación.
 * - Emite whatsapp.inbound **sin el texto** (nunca se guarda en ClickHouse).
 */
final class InboundHandler
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly HubEvents $hubEvents,
        private readonly MessageEvents $events,
        private readonly UnsubscribeService $unsubscribe,
    ) {}

    public function handle(ChannelAccount $account, InboundMessage $in): ?Message
    {
        $workspace = Workspace::query()->findOrFail($account->workspace_id);

        return $this->current->run($workspace, function () use ($workspace, $account, $in): ?Message {
            if (Message::query()->where('provider_message_id', $in->providerMessageId)->where('direction', 'in')->exists()) {
                return null; // el proveedor reintentó el webhook
            }
            $e164 = Phone::toE164($in->from, strtoupper((string) ($workspace->country ?: 'CO')));
            if ($e164 === null) {
                return null;
            }
            $contact = $this->contact($workspace, $e164);
            $message = Message::query()->create([
                'workspace_id' => $workspace->id,
                'contact_id' => $contact->id,
                'channel_account_id' => $account->id,
                'channel' => $account->channel,
                'provider' => $account->provider,
                'provider_message_id' => $in->providerMessageId,
                'direction' => 'in',
                'kind' => MessageKind::Service,
                'source_type' => 'inbox',
                'status' => 'received',
                'payload' => ['type' => $in->type, 'button_payload' => $in->buttonPayload],
                'queued_at' => $in->at,
            ]);
            if ($account->channel === Channel::WhatsApp) {
                $conversation = Conversation::query()->firstOrNew(['channel_account_id' => $account->id, 'contact_id' => $contact->id]);
                $conversation->fill(['workspace_id' => $workspace->id, 'status' => 'open', 'last_inbound_at' => $in->at, 'window_expires_at' => $in->at->addDay()])->save();
                $message->forceFill(['conversation_id' => $conversation->id])->save();
            }

            $keyword = self::unsubscribeKeyword($in->text);
            $this->markReplied($workspace, $contact, $in, $keyword);
            $waRef = self::waRef($in->text);
            if ($waRef !== null) {
                $this->linkVisitor($workspace, $contact, $waRef);
            }
            if ($keyword !== null) {
                $channel = $account->channel->value;
                $last = Message::query()->where('contact_id', $contact->id)->where('direction', 'out')->where('channel', $channel)
                    ->where('kind', MessageKind::Marketing->value)->latest('created_at')->first();
                $revoked = $this->unsubscribe->unsubscribe($workspace, $contact->id, $channel, 'channel', 'keyword', $last);
                if ($revoked !== []) {
                    $this->confirm($workspace, $account, $contact);
                }
            }
            if ($account->channel === Channel::WhatsApp) {
                $this->emitInbound($workspace, $account, $contact, $message, $in, $keyword, $waRef);
            }

            return $message;
        });
    }

    private function contact(Workspace $workspace, string $e164): Contact
    {
        $bidx = BlindIndex::phone($workspace->id, $e164);
        $contact = Contact::query()->where('phone_bidx', $bidx)->whereNull('merged_into_id')->first();
        if ($contact === null) {
            $owner = ContactIdentity::query()->where('type', 'phone_hash')->where('value', $bidx)->value('contact_id');
            $contact = is_string($owner) ? Contact::query()->find($owner) : null;
        }
        if ($contact === null) {
            $contact = Contact::query()->create([
                'workspace_id' => $workspace->id,
                'phone' => $e164,
                'phone_bidx' => $bidx,
                'country' => self::countryOf($e164),
                'lifecycle_stage' => 'lead',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            ContactIdentity::query()->insertOrIgnore([
                'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $workspace->id, 'contact_id' => $contact->id,
                'type' => 'phone_hash', 'value' => $bidx, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        return $contact;
    }

    private function markReplied(Workspace $workspace, Contact $contact, InboundMessage $in, ?string $keyword): void
    {
        $original = $in->contextMessageId !== null
            ? Message::query()->where('provider_message_id', $in->contextMessageId)->where('direction', 'out')->first()
            : Message::query()->where('contact_id', $contact->id)->where('direction', 'out')->whereNotNull('sent_at')
                ->where('sent_at', '>=', now()->subDays(3))->latest('sent_at')->first();
        if ($original === null) {
            return;
        }
        if ($original->replied_at === null) {
            $original->forceFill(['replied_at' => $in->at])->save();
        }
        $this->events->emit($workspace, $original, 'message.replied', [
            'button_payload' => $in->buttonPayload !== null ? mb_substr($in->buttonPayload, 0, 200) : null,
            'keyword' => $keyword,
        ], $in->at);
    }

    /** Vincula el número con el visitante anónimo que hizo clic al botón de WhatsApp (eventos.md §6.3). */
    private function linkVisitor(Workspace $workspace, Contact $contact, string $waRef): void
    {
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT anonymous_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'cta.clicked'
                   AND JSONExtractString(toJSONString(properties), 'wa_ref') = {ref:String} AND occurred_at >= now() - INTERVAL 30 DAY
                   AND anonymous_id != '' ORDER BY occurred_at DESC LIMIT 1",
                ['ref' => $waRef],
            );
        } catch (Throwable $e) {
            report($e);

            return;
        }
        $anon = (string) ($rows[0]['anonymous_id'] ?? '');
        if ($anon === '') {
            return;
        }
        $owner = ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $anon)->value('contact_id');
        if ($owner === null) {
            ContactIdentity::query()->insertOrIgnore([
                'id' => strtoupper((string) Str::ulid()), 'workspace_id' => $workspace->id, 'contact_id' => $contact->id,
                'type' => 'anonymous_id', 'value' => $anon, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
    }

    private function confirm(Workspace $workspace, ChannelAccount $account, Contact $contact): void
    {
        try {
            app(MessageDispatcher::class)->dispatch(new OutboundMessage(
                $workspace->id, $contact->id, $account->channel, MessageKind::Service, 'system',
                context: ['text' => __('Listo, ya no te enviaremos promociones por este medio. Si fue un error, escríbenos.')],
                channelAccountId: $account->id,
            ));
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.messaging.unsubscribe_confirm_failed', ['account' => $account->id]);
        }
    }

    private function emitInbound(Workspace $workspace, ChannelAccount $account, Contact $contact, Message $message, InboundMessage $in, ?string $keyword, ?string $waRef): void
    {
        try {
            $this->hubEvents->emit($workspace, 'whatsapp.inbound', array_filter([
                'message_id' => $message->publicId(),
                'channel_account_id' => $account->publicId(),
                'type' => $in->type,
                'button_payload' => $in->buttonPayload !== null ? mb_substr($in->buttonPayload, 0, 200) : null,
                'keyword' => $keyword,
                'wa_ref' => $waRef,
                'ctwa_clid' => $in->ctwaClid !== null ? mb_substr($in->ctwaClid, 0, 200) : null,
                'referral_source_type' => $in->referralSourceType !== null ? mb_substr($in->referralSourceType, 0, 20) : null,
                'referral_source_id' => $in->referralSourceId !== null ? mb_substr($in->referralSourceId, 0, 60) : null,
                'provider' => $account->provider,
            ], fn ($v) => $v !== null), $contact->id, null, $in->at);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** La palabra de baja normalizada, o null (hub/CLAUDE.md §8). Solo mensajes cortos, para no confundir «no más dudas, gracias». */
    public static function unsubscribeKeyword(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $normalized = trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(Str::ascii($text))));
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);
        $normalized = (string) preg_replace('/^(por favor|porfa|hola)\s+|\s+(por favor|porfa|gracias)$/', '', $normalized);
        if ($normalized === '') {
            return null;
        }
        $keywords = (array) config('igr-messaging.unsubscribe_keywords');
        if (in_array($normalized, $keywords, true)) {
            return $normalized;
        }
        // «Quiero la baja», «baja por favor»: «baja» dentro de un mensaje de hasta 4 palabras.
        if (str_word_count($normalized) <= 4 && preg_match('/\bbaja\b/', $normalized) === 1) {
            return 'baja';
        }

        return null;
    }

    private static function countryOf(string $e164): ?string
    {
        try {
            $util = PhoneNumberUtil::getInstance();

            return $util->getRegionCodeForNumber($util->parse($e164)) ?: null;
        } catch (NumberParseException) {
            return null;
        }
    }

    /** Token del sitio en el texto prellenado de WhatsApp: «(ref: K7Q2)». */
    public static function waRef(?string $text): ?string
    {
        return $text !== null && preg_match('/\bref:\s*([A-Za-z0-9]{4,12})\b/i', $text, $m) === 1 ? strtoupper($m[1]) : null;
    }
}
