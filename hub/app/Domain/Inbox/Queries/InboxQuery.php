<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Inbox\Models\ConversationNote;
use App\Domain\Inbox\Models\MessageAttachment;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Coexistence\BusinessAppMessages;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\ConversationWindow;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Phone\Phone;
use App\Support\Pii\BlindIndex;
use App\Support\Pii\Pii;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lo que muestra la bandeja (M11): la lista de conversaciones, el chat y el panel
 * del contacto. `$userId` y `$seesAll` aplican la regla de visibilidad: quien no
 * ve todos los contactos (rol agente) solo ve las conversaciones que tiene asignadas.
 */
final class InboxQuery
{
    /**
     * @param  array{view?: string|null, status?: string|null, label?: string|null, intent?: string|null, q?: string|null, before?: string|null, channel?: string|null}  $f
     * @return array{data: list<array<string, mixed>>, next_before: string|null, counts: array<string, int>, accounts: list<array{id: string, channel: string, name: string, identity: string|null, status: string}>}
     */
    public function list(Workspace $workspace, string $userId, bool $seesAll, array $f, int $limit = 30): array
    {
        $base = fn (): Builder => $this->visible(Conversation::query(), $userId, $seesAll);
        $query = $base();
        match ($f['view'] ?? 'all') {
            'mine' => $query->where('assignee_id', $userId),
            'unassigned' => $query->whereNull('assignee_id'),
            'ai' => $query->whereIn('ai_mode', ['assist', 'auto']),
            default => null,
        };
        $status = $f['status'] ?? 'open';
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (($f['label'] ?? null) !== null && $f['label'] !== '') {
            $query->whereJsonContains('labels', (string) $f['label']);
        }
        if (($f['intent'] ?? null) !== null && $f['intent'] !== '') {
            $query->where('intent', (string) $f['intent']);
        }
        if (($f['channel'] ?? null) !== null && $f['channel'] !== '') {
            $query->whereIn('channel_account_id', ChannelAccount::query()->where('channel', (string) $f['channel'])->select('id'));
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $query->whereIn('contact_id', $this->searchContacts($workspace, $q));
        }
        if (($f['before'] ?? null) !== null && $f['before'] !== '') {
            $query->where('last_message_at', '<', $f['before']);
        }
        $rows = $query->orderByDesc('last_message_at')->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $contacts = Contact::query()->whereIn('id', $rows->pluck('contact_id'))->get()->keyBy('id');
        $users = $this->userNames(array_values(array_map('strval', $rows->pluck('assignee_id')->filter()->unique()->all())));
        $last = $this->lastMessages(array_values(array_map('strval', $rows->modelKeys())));
        $data = $rows->map(function (Conversation $c) use ($contacts, $users, $last): array {
            $message = $last[$c->id] ?? null;

            return $this->summary($c, $contacts->get($c->contact_id), $users) + ['preview' => $message !== null ? self::preview($message) : null];
        })->values()->all();

        $counts = [
            'mine' => $base()->where('status', 'open')->where('assignee_id', $userId)->count(),
            'unassigned' => $base()->where('status', 'open')->whereNull('assignee_id')->count(),
            'unread' => $base()->where('status', 'open')->where('unread_count', '>', 0)->count(),
        ];

        /** @var list<array<string, mixed>> $data */
        return ['data' => $data, 'next_before' => $more && $rows->last()?->last_message_at !== null ? $rows->last()->last_message_at->toIso8601String() : null, 'counts' => $counts,
            'accounts' => $this->accounts()];
    }

    /**
     * Las cuentas que conversan en la bandeja (WhatsApp, Messenger, Instagram) con su estado, para que la bandeja diga
     * en palabras si alguna está desconectada o con error y hay que reconectarla para responder.
     *
     * @return list<array{id: string, channel: string, name: string, identity: string|null, status: string}>
     */
    private function accounts(): array
    {
        return array_values(ChannelAccount::query()->whereIn('channel', [Channel::WhatsApp->value, Channel::Messenger->value, Channel::Instagram->value])
            ->whereIn('status', ['active', 'paused', 'error', 'disconnected'])->orderBy('created_at')->limit(20)->get()
            ->map(fn (ChannelAccount $a): array => [
                'id' => $a->publicId(), 'channel' => $a->channel->value, 'name' => (string) $a->display_name,
                'identity' => $a->identity !== null ? (string) $a->identity : null, 'status' => (string) $a->status,
            ])->all());
    }

    /** @return array<string, mixed> la conversación con sus mensajes (los 50 más recientes antes de `before`) y sus notas */
    public function detail(Conversation $c, ?string $before = null): array
    {
        $contact = Contact::query()->find($c->contact_id);
        $messages = Message::query()->where('conversation_id', $c->id)
            ->when($before !== null, fn ($q) => $q->where('created_at', '<', $before))
            ->latest('created_at')->limit(50)->get()->reverse()->values();
        $authors = [];
        foreach (ConversationEvent::query()->where('conversation_id', $c->id)->where('kind', 'message')->get() as $e) {
            $authors[(string) ($e->data['message'] ?? '')] = $e->actor;
        }
        $names = $this->userNames(array_values(array_filter(array_unique(array_values($authors)), fn ($a) => ! in_array($a, ['ai', 'system', BusinessAppMessages::ACTOR], true))));
        $attachments = MessageAttachment::query()->whereIn('message_id', $messages->pluck('id'))->get()->groupBy('message_id');

        $account = ChannelAccount::query()->find($c->channel_account_id);
        $caps = $account !== null ? app(DriverRegistry::class)->capabilitiesOf($account) : null;

        return $this->summary($c, $contact, $this->userNames(array_filter([$c->assignee_id]))) + [
            // Qué puede mandar el número de esta conversación: sin botones las opciones salen numeradas; sin plantillas de
            // Meta, la plantilla sale como texto (ChannelCapabilities).
            'account' => $account === null || $caps === null ? null : [
                'name' => (string) $account->display_name, 'provider' => (string) $account->provider, 'official' => $caps->official,
                'templates' => $caps->templates, 'buttons' => $caps->buttons, 'media' => $caps->media !== [], 'options_as' => $caps->optionsAs(),
            ],
            'messages' => $messages->map(function (Message $m) use ($authors, $names, $attachments): array {
                $payload = (array) $m->payload;
                $author = $authors[$m->publicId()] ?? null;
                // Coexistencia: lo que el negocio escribió en la app del celular (en el momento o en el historial copiado).
                $fromPhone = $author === BusinessAppMessages::ACTOR || ($author === null && $m->direction === 'out' && in_array($m->source_type, BusinessAppMessages::SOURCES, true));

                return [
                    'id' => $m->publicId(),
                    'direction' => $m->direction,
                    'kind' => $m->kind->value,
                    'source' => $m->source_type,
                    'type' => ! empty($payload['revoked']) ? 'revoked' : (string) ($payload['type'] ?? (is_array($payload['wa'] ?? null) ? 'template' : 'text')),
                    'text' => isset($payload['text']) && is_string($payload['text']) ? $payload['text'] : null,
                    'edited' => ! empty($payload['edited']),
                    'template' => is_array($payload['wa'] ?? null) ? (string) ($payload['wa']['name'] ?? '') : null,
                    'status' => $m->status,
                    'author' => match (true) {
                        $fromPhone => ['kind' => 'phone', 'name' => __('Desde el celular')],
                        $author === null => null,
                        $author === 'ai' => ['kind' => 'ai', 'name' => __('Asistente IA')],
                        default => ['kind' => 'user', 'name' => $names[$author] ?? __('Equipo')],
                    },
                    'media_url' => is_string($payload['media']['url'] ?? null) ? (string) $payload['media']['url'] : null,
                    'attachments' => ($attachments->get($m->id) ?? collect())->map(fn (MessageAttachment $a) => ['kind' => $a->kind, 'mime' => $a->mime, 'caption' => $a->caption])->values()->all(),
                    'failed_reason' => $m->getAttribute('failed_reason'),
                    // Lo que el proveedor no pudo mandar de una plantilla compuesta como texto (p. ej. la imagen del encabezado).
                    'degraded' => array_values(array_filter((array) ($payload['degraded'] ?? []), 'is_string')),
                    'at' => ($m->getAttribute('sent_at') ?? $m->queued_at ?? $m->created_at)?->toIso8601String(),
                    'delivered_at' => $m->getAttribute('delivered_at')?->toIso8601String(),
                    'read_at' => $m->getAttribute('read_at')?->toIso8601String(),
                ];
            })->all(),
            'has_more' => $messages->count() === 50,
            'notes' => ConversationNote::query()->where('conversation_id', $c->id)->latest()->limit(50)->get()
                ->map(fn (ConversationNote $n) => ['id' => $n->publicId(), 'body' => $n->body, 'author' => $this->userNames(array_filter([$n->user_id]))[$n->user_id] ?? null, 'at' => $n->created_at?->toIso8601String()])->all(),
        ];
    }

    /** @return array<string, mixed> el panel del contacto al lado del chat */
    public function panel(Workspace $workspace, Conversation $c): array
    {
        $contact = Contact::query()->find($c->contact_id);
        if ($contact === null) {
            return [];
        }
        $ids = ExportContactData::contactIds($contact);
        $orders = Order::query()->whereIn('contact_id', $ids)->latest('created_at')->limit(5)->get();
        $cart = Cart::query()->whereIn('contact_id', $ids)->whereIn('status', ['active', 'abandoned'])->latest('last_activity_at')->first();
        $tier = $contact->getAttribute('tier_id') !== null ? DB::table('loyalty_tiers')->where('id', $contact->getAttribute('tier_id'))->value('name') : null;
        $segments = DB::table('segment_memberships as m')->join('segments as s', 's.id', '=', 'm.segment_id')
            ->where('m.workspace_id', $workspace->id)->whereIn('m.contact_id', $ids)->orderBy('s.name')->limit(6)->pluck('s.name')->all();
        $origin = Message::query()->where('conversation_id', $c->id)->where('direction', 'in')->oldest('created_at')->first();
        $originPayload = $origin !== null ? (array) $origin->payload : [];

        return [
            'contact' => [
                'id' => $contact->publicId(),
                'name' => self::name($contact, $this->accountChannel($c->channel_account_id)),
                'phone' => is_string($contact->phone) ? Pii::maskPhone($contact->phone) : null,
                'city' => $contact->getAttribute('city'),
                'lifecycle_stage' => $contact->getAttribute('lifecycle_stage'),
                'orders_count' => (int) $contact->getAttribute('orders_count'),
                'revenue_total' => (string) ($contact->getAttribute('revenue_total_ws') ?? '0'),
                'rfm_segment' => $contact->getAttribute('rfm_segment'),
                'points' => (int) $contact->getAttribute('points_balance'),
                'tier' => $tier,
                'wallet' => (string) ($contact->getAttribute('wallet_balance_ws') ?? '0'),
            ],
            'orders' => $orders->map(fn (Order $o) => ['id' => $o->publicId(), 'number' => $o->getAttribute('number'), 'status' => $o->status, 'total' => (string) $o->total,
                'currency' => $o->getAttribute('currency'), 'created_at' => $o->created_at?->toIso8601String()])->all(),
            'cart' => $cart === null ? null : [
                'status' => $cart->status, 'items_count' => (int) $cart->getAttribute('items_count'), 'subtotal' => (string) $cart->getAttribute('subtotal'),
                'currency' => $cart->getAttribute('currency'), 'items' => array_values(array_map(fn ($i) => ['name' => is_array($i) ? ($i['name'] ?? null) : null, 'qty' => is_array($i) ? ($i['qty'] ?? 1) : 1], array_slice((array) $cart->items, 0, 5))),
                'restore_url' => self::safe(fn () => CartRestoreLink::for($cart)),
            ],
            'segments' => $segments,
            'last_product_viewed' => $this->lastViewed($workspace, $ids),
            'origin' => [
                // Una respuesta a una historia de Instagram no es un anuncio (Fase 5).
                'ad' => isset($originPayload['ctwa_clid']) || (isset($originPayload['referral_source']) && $originPayload['referral_source'] !== 'story'),
                'source' => $originPayload['referral_source'] ?? null,
                'first_source' => $contact->getAttribute('first_source'),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $users
     * @return array<string, mixed>
     */
    private function summary(Conversation $c, ?Contact $contact, array $users): array
    {
        $window = $c->window_expires_at;
        $channel = $this->accountChannel($c->channel_account_id);
        $state = ConversationWindow::state($channel, $window, $c->last_inbound_at);

        return [
            'id' => $c->publicId(),
            'contact' => $contact === null ? null : ['id' => $contact->publicId(), 'name' => self::name($contact, $channel)],
            'channel' => $channel->value,
            'window_state' => $state,
            'human_agent_until' => $state === ConversationWindow::HUMAN_AGENT ? $c->last_inbound_at?->copy()->addDays(ConversationWindow::HUMAN_AGENT_DAYS)->toIso8601String() : null,
            'status' => $c->status,
            'assignee' => $c->assignee_id !== null ? ['id' => PrefixedId::encode(User::ID_PREFIX, $c->assignee_id), 'name' => $users[$c->assignee_id] ?? null] : null,
            'labels' => array_values((array) $c->labels),
            'intent' => $c->intent,
            'ai_mode' => $c->ai_mode,
            'handoff_reason' => $c->handoff_reason,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'awaiting_since' => self::iso($c->getAttribute('awaiting_since')),
            'window_expires_at' => $window?->toIso8601String(),
            'window_open' => $window !== null && $window->isFuture(),
            'first_response_seconds' => $c->first_response_seconds,
        ];
    }

    /** @return array{direction: string, text: string|null, at: string|null} */
    private static function preview(Message $m): array
    {
        $payload = (array) $m->payload;
        $text = is_string($payload['text'] ?? null) ? (string) $payload['text'] : (is_array($payload['wa'] ?? null) ? '['.($payload['wa']['name'] ?? 'plantilla').']' : null);

        return ['direction' => $m->direction, 'text' => $text !== null ? mb_substr($text, 0, 90) : null, 'at' => $m->created_at?->toIso8601String()];
    }

    /**
     * @param  Builder<Conversation>  $q
     * @return Builder<Conversation>
     */
    public function visible(Builder $q, string $userId, bool $seesAll): Builder
    {
        return $seesAll ? $q : $q->where('assignee_id', $userId);
    }

    /** @return list<string> */
    private function searchContacts(Workspace $workspace, string $q): array
    {
        $query = Contact::query()->whereNull('merged_into_id')->where(function (Builder $w) use ($q, $workspace): void {
            $w->where('email_bidx', BlindIndex::email($workspace->id, $q));
            $digits = preg_replace('/[^0-9+]/', '', $q) ?? '';
            if (strlen($digits) >= 7) {
                $e164 = Phone::toE164($digits, (string) ($workspace->country ?: 'CO'));
                if ($e164 !== null) {
                    $w->orWhere('phone_bidx', BlindIndex::phone($workspace->id, $e164));
                }
            }
            $word = mb_strtolower(explode(' ', $q)[0]);
            if (mb_strlen($word) >= 2) {
                $w->orWhereJsonContains('name_prefix_bidx', substr(BlindIndex::value($workspace->id, 'name', mb_substr($word, 0, 6)), 0, 16));
            }
        });

        return array_values($query->limit(200)->pluck('id')->map(fn ($v) => (string) $v)->all());
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function userNames(array $ids): array
    {
        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->get(['id', 'name'])->mapWithKeys(fn (User $u) => [$u->id => (string) $u->name])->all();
    }

    /**
     * El último mensaje de cada conversación con dos consultas para toda la página (antes, una por conversación):
     * la fecha más reciente por conversación sale del índice (workspace_id, conversation_id, created_at) y, si dos
     * mensajes comparten esa fecha, gana el de id mayor (los ULID crecen con el tiempo).
     *
     * @param  list<string>  $conversationIds
     * @return array<string, Message>
     */
    private function lastMessages(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        $latest = Message::query()->whereIn('conversation_id', $conversationIds)->groupBy('conversation_id')
            ->selectRaw('conversation_id, MAX(created_at) AS last_at')->toBase()->get()
            ->filter(fn (object $r) => $r->last_at !== null);
        if ($latest->isEmpty()) {
            return [];
        }
        $messages = Message::query()->where(function (Builder $q) use ($latest): void {
            foreach ($latest as $r) {
                $q->orWhere(fn (Builder $w) => $w->where('conversation_id', $r->conversation_id)->where('created_at', $r->last_at));
            }
        })->orderBy('id')->get();
        $out = [];
        foreach ($messages as $m) {
            $out[(string) $m->conversation_id] = $m; // ordenados por id: el último que queda es el de id mayor
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array{product_id: string, name: string, at: string}|null
     */
    private function lastViewed(Workspace $workspace, array $ids): ?array
    {
        $anon = ContactIdentity::query()->whereIn('contact_id', $ids)->where('type', 'anonymous_id')->pluck('value')->map(fn ($v) => (string) $v)->all();
        $row = self::safe(fn () => Ch::forWorkspace($workspace)->select(
            "SELECT product_id, JSONExtractString(toJSONString(properties), 'name') AS name, formatDateTime(occurred_at, '%Y-%m-%dT%H:%i:%SZ', 'UTC') AS at
             FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'product.viewed'
               AND (has({ids:Array(String)}, contact_id) OR has({anon:Array(String)}, anonymous_id))
             ORDER BY occurred_at DESC LIMIT 1",
            ['ids' => ExportContactData::chArray($ids), 'anon' => ExportContactData::chArray($anon)],
        )[0] ?? null);

        return is_array($row) ? ['product_id' => (string) $row['product_id'], 'name' => (string) $row['name'], 'at' => (string) $row['at']] : null;
    }

    public static function name(Contact $contact, ?Channel $channel = null): string
    {
        $name = trim(((string) $contact->first_name).' '.((string) $contact->last_name));
        if ($name === '' && ! is_string($contact->phone) && $channel !== null && $channel->isSocial()) {
            return $channel === Channel::Instagram ? __('Cliente de Instagram') : __('Cliente de Messenger');
        }

        return $name !== '' ? $name : (is_string($contact->phone) ? Pii::maskPhone($contact->phone) : __('Sin nombre'));
    }

    /** @var array<string, Channel> */
    private array $channels = [];

    /** Canal de la cuenta de la conversación (WhatsApp, Messenger o Instagram), con caché por consulta. */
    private function accountChannel(string $accountId): Channel
    {
        if (! isset($this->channels[$accountId])) {
            $this->channels[$accountId] = ChannelAccount::query()->find($accountId)->channel ?? Channel::WhatsApp;
        }

        return $this->channels[$accountId];
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (is_string($value) ? CarbonImmutable::parse($value)->toIso8601String() : null);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    private static function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
