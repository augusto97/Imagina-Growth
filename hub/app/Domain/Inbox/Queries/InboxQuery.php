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
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
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
     * @param  array{view?: string|null, status?: string|null, label?: string|null, intent?: string|null, q?: string|null, before?: string|null}  $f
     * @return array{data: list<array<string, mixed>>, next_before: string|null, counts: array<string, int>}
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
        $data = $rows->map(function (Conversation $c) use ($contacts, $users): array {
            $last = Message::query()->where('conversation_id', $c->id)->latest('created_at')->first();

            return $this->summary($c, $contacts->get($c->contact_id), $users) + ['preview' => $last !== null ? self::preview($last) : null];
        })->values()->all();

        $counts = [
            'mine' => $base()->where('status', 'open')->where('assignee_id', $userId)->count(),
            'unassigned' => $base()->where('status', 'open')->whereNull('assignee_id')->count(),
            'unread' => $base()->where('status', 'open')->where('unread_count', '>', 0)->count(),
        ];

        /** @var list<array<string, mixed>> $data */
        return ['data' => $data, 'next_before' => $more && $rows->last()?->last_message_at !== null ? $rows->last()->last_message_at->toIso8601String() : null, 'counts' => $counts];
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
        $names = $this->userNames(array_values(array_filter(array_unique(array_values($authors)), fn ($a) => $a !== 'ai' && $a !== 'system')));
        $attachments = MessageAttachment::query()->whereIn('message_id', $messages->pluck('id'))->get()->groupBy('message_id');

        return $this->summary($c, $contact, $this->userNames(array_filter([$c->assignee_id]))) + [
            'messages' => $messages->map(function (Message $m) use ($authors, $names, $attachments): array {
                $payload = (array) $m->payload;
                $author = $authors[$m->publicId()] ?? null;

                return [
                    'id' => $m->publicId(),
                    'direction' => $m->direction,
                    'kind' => $m->kind->value,
                    'source' => $m->source_type,
                    'type' => (string) ($payload['type'] ?? (is_array($payload['wa'] ?? null) ? 'template' : 'text')),
                    'text' => isset($payload['text']) && is_string($payload['text']) ? $payload['text'] : null,
                    'template' => is_array($payload['wa'] ?? null) ? (string) ($payload['wa']['name'] ?? '') : null,
                    'status' => $m->status,
                    'author' => $author === null ? null : ($author === 'ai' ? ['kind' => 'ai', 'name' => __('Asistente IA')] : ['kind' => 'user', 'name' => $names[$author] ?? __('Equipo')]),
                    'attachments' => ($attachments->get($m->id) ?? collect())->map(fn (MessageAttachment $a) => ['kind' => $a->kind, 'mime' => $a->mime, 'caption' => $a->caption])->values()->all(),
                    'failed_reason' => $m->getAttribute('failed_reason'),
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
                'name' => self::name($contact),
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
                'ad' => isset($originPayload['ctwa_clid']) || isset($originPayload['referral_source']),
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

        return [
            'id' => $c->publicId(),
            'contact' => $contact === null ? null : ['id' => $contact->publicId(), 'name' => self::name($contact)],
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

    public static function name(Contact $contact): string
    {
        $name = trim(((string) $contact->first_name).' '.((string) $contact->last_name));

        return $name !== '' ? $name : (is_string($contact->phone) ? Pii::maskPhone($contact->phone) : __('Sin nombre'));
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
