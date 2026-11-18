<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Http;

use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Inbox\Actions\InboxPresence;
use App\Domain\Inbox\Actions\SendInboxMessage;
use App\Domain\Inbox\Actions\UpdateConversation;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Queries\InboxMetrics;
use App\Domain\Inbox\Queries\InboxQuery;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * API de la bandeja (M11) dentro de /api/w/{workspace}. Ver y responder pide
 * `useInbox`; quien no ve todos los contactos (rol agente) solo ve y toca las
 * conversaciones que tiene asignadas.
 */
final class InboxController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly InboxQuery $query) {}

    /** GET inbox/conversations */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $f = $request->validate([
            'view' => ['nullable', Rule::in(['all', 'mine', 'unassigned', 'ai'])],
            'status' => ['nullable', Rule::in(['open', 'pending', 'closed', 'all'])],
            'label' => ['nullable', 'string', 'max:32'],
            'intent' => ['nullable', 'string', 'max:16'],
            'q' => ['nullable', 'string', 'max:120'],
            'before' => ['nullable', 'date'],
            'channel' => ['nullable', Rule::in(['whatsapp', 'messenger', 'instagram'])],
        ]);

        return response()->json($this->query->list($this->current->get(), $this->userId($request), $this->seesAll(), $f));
    }

    /** GET inbox/conversations/{id} */
    public function show(Request $request, string $conversation): JsonResponse
    {
        $c = $this->find($request, $conversation);

        return response()->json($this->query->detail($c, $request->query('before') !== null ? (string) $request->query('before') : null));
    }

    /** GET inbox/conversations/{id}/panel */
    public function panel(Request $request, string $conversation): JsonResponse
    {
        return response()->json($this->query->panel($this->current->get(), $this->find($request, $conversation)));
    }

    /** POST inbox/conversations/{id}/messages */
    public function send(Request $request, string $conversation, SendInboxMessage $send): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $data = $request->validate([
            'type' => ['required', Rule::in(['text', 'template', 'product', 'cart_link', 'payment_link', 'coupon'])],
            'client_id' => ['required', 'uuid'],
            'text' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'template_id' => ['required_if:type,template', 'nullable', 'string', 'max:40'],
            'variables' => ['nullable', 'array', 'max:20'],
            'product_id' => ['required_if:type,product', 'nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required_if:type,cart_link', 'required_if:type,payment_link', 'nullable', 'array', 'max:20'],
            'coupon' => ['nullable', 'string', 'max:40'],
            'kind' => ['nullable', Rule::in(['percent', 'fixed_cart', 'free_shipping'])],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        try {
            $result = $send->handle($c, $data, $this->userId($request));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return match ($result['status']) {
            DispatchResult::QUEUED, DispatchResult::DUPLICATE => response()->json($result, $result['status'] === DispatchResult::QUEUED ? 201 : 200),
            DispatchResult::DEFERRED => response()->json($result + ['message' => __('Por la hora legal del contacto, el mensaje no se puede enviar ahora. Intenta después de :at.', ['at' => (string) $result['until']])], 409),
            default => response()->json($result + ['message' => self::denied((string) $result['reason'], ChannelAccount::query()->find($c->channel_account_id)?->channel)], 422),
        };
    }

    /** POST inbox/conversations/{id}/assign {user_id|null} */
    public function assign(Request $request, string $conversation, AssignConversation $assign): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $data = $request->validate(['user_id' => ['nullable', 'string', 'max:40']]);
        $userId = $data['user_id'] !== null ? PrefixedId::decode(User::ID_PREFIX, (string) $data['user_id']) : null;
        // Un agente solo puede soltar la suya; reasignar a otros es de quien ve todo.
        if (! $this->seesAll() && $userId !== null && $userId !== $this->userId($request)) {
            abort(403);
        }
        try {
            $assign->assign($c, $userId, $this->userId($request));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->query->detail($c->refresh()));
    }

    /** PATCH inbox/conversations/{id} {status?, labels?, ai_mode?} */
    public function update(Request $request, string $conversation, UpdateConversation $update): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(UpdateConversation::STATUSES)],
            'labels' => ['sometimes', 'array', 'max:10'],
            'labels.*' => ['string', 'max:32'],
            'ai_mode' => ['sometimes', Rule::in(UpdateConversation::AI_MODES)],
        ]);
        $actor = $this->userId($request);
        try {
            if (isset($data['status'])) {
                $update->status($c, $data['status'], $actor);
            }
            if (array_key_exists('labels', $data)) {
                $update->labels($c, array_values((array) $data['labels']), $actor);
            }
            if (isset($data['ai_mode'])) {
                $update->aiMode($c, $data['ai_mode'], $actor);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->query->detail($c->refresh()));
    }

    /** POST inbox/conversations/{id}/handoff {reason} */
    public function handoff(Request $request, string $conversation, UpdateConversation $update): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:64']]);
        $update->handoff($c, (string) ($data['reason'] ?? 'manual'), $this->userId($request));

        return response()->json($this->query->detail($c->refresh()));
    }

    /** POST inbox/conversations/{id}/read */
    public function read(Request $request, string $conversation, UpdateConversation $update): JsonResponse
    {
        $update->markRead($this->find($request, $conversation));

        return response()->json(['ok' => true]);
    }

    /** POST inbox/conversations/{id}/notes {body} */
    public function note(Request $request, string $conversation, UpdateConversation $update): JsonResponse
    {
        $c = $this->find($request, $conversation);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        try {
            $note = $update->note($c, (string) $data['body'], $this->userId($request));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $note->publicId(), 'body' => $note->body, 'at' => $note->created_at?->toIso8601String()], 201);
    }

    /** POST inbox/conversations/{id}/suggest: sugerencia del agente de IA */
    public function suggest(Request $request, string $conversation, ConversationAgent $agent): JsonResponse
    {
        $c = $this->find($request, $conversation);

        return response()->json(['suggestion' => $agent->suggest($c)]);
    }

    /** GET inbox/products?q= (catálogo del hub; el precio y el stock en vivo se consultan al enviar) */
    public function products(Request $request): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $q = trim((string) $request->query('q', ''));
        $rows = DB::table('products')->where('workspace_id', $this->current->id())->whereNull('deleted_at')->where('status', 'publish')
            ->whereIn('type', ['simple', 'variable'])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')->orWhere('sku', $q)))
            ->orderByDesc('purchases_30d')->limit(12)->get(['external_id', 'name', 'sku', 'price', 'currency', 'stock_status', 'image_url', 'permalink']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => (string) $r->external_id, 'name' => (string) $r->name, 'sku' => $r->sku, 'price' => $r->price,
            'currency' => $r->currency, 'in_stock' => $r->stock_status !== 'outofstock', 'image' => $r->image_url, 'url' => $r->permalink])->all()]);
    }

    /** GET inbox/templates: plantillas de WhatsApp listas */
    public function templates(): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $rows = Template::query()->where('channel', 'whatsapp')->where('status', 'ready')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (Template $t) => ['id' => $t->publicId(), 'name' => $t->name, 'category' => $t->category,
            'variables' => self::freeVariables((array) ($t->variables ?? []))])->all()]);
    }

    /** GET inbox/agents: quién atiende la bandeja y si está disponible */
    public function agents(InboxPresence $presence): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $ids = AssignConversation::agents($this->current->id());
        $users = User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->publicId(), 'name' => $u->name, 'online' => $presence->isOnline($this->current->id(), $u->id)])->all()]);
    }

    /** POST inbox/presence {available} */
    public function presence(Request $request, InboxPresence $presence): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $presence->heartbeat($this->current->id(), $this->userId($request), (bool) $data['available']);

        return response()->json(['ok' => true]);
    }

    /** GET inbox/metrics?days= */
    public function metrics(Request $request, InboxMetrics $metrics): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $days = (int) $request->validate(['days' => ['nullable', Rule::in(['7', '30', '90', 7, 30, 90])]])['days'] ?: 30;

        return response()->json($metrics->handle($this->current->get(), $days));
    }

    private function find(Request $request, string $publicId): Conversation
    {
        Gate::authorize('ws.useInbox');
        $id = PrefixedId::decode(Conversation::ID_PREFIX, $publicId);
        $c = $id !== null ? $this->query->visible(Conversation::query(), $this->userId($request), $this->seesAll())->find($id) : null;
        abort_if($c === null, 404);

        return $c;
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    private function seesAll(): bool
    {
        return Gate::allows('ws.viewAllContacts');
    }

    /**
     * Las variables de una plantilla que escribe el agente (`{{ bandeja.<clave> }}`).
     *
     * @param  array<mixed>  $mapping
     * @return list<string>
     */
    private static function freeVariables(array $mapping): array
    {
        $keys = [];
        array_walk_recursive($mapping, function (mixed $v) use (&$keys): void {
            if (is_string($v) && preg_match_all('/bandeja\.([A-Za-z0-9_]+)/', $v, $m) > 0) {
                array_push($keys, ...$m[1]);
            }
        });

        return array_values(array_unique($keys));
    }

    private static function denied(string $reason, ?Channel $channel = null): string
    {
        if ($channel !== null && $channel->isSocial()) {
            $name = $channel === Channel::Instagram ? 'Instagram' : 'Messenger';

            return match ($reason) {
                'outside_24h_window' => $channel === Channel::Messenger
                    ? __('Pasaron más de 24 horas desde el último mensaje del cliente: en Messenger solo una persona del equipo puede responder, hasta 7 días después.')
                    : __('Ventana cerrada: pasaron más de 24 horas desde el último mensaje del cliente. En Instagram solo puedes responder cuando vuelva a escribir.'),
                'outside_human_agent_window' => __('Pasaron más de 7 días desde el último mensaje del cliente: en Messenger ya no se le puede escribir hasta que vuelva a hacerlo.'),
                'channel_unavailable' => __('La cuenta de :channel no está activa.', ['channel' => $name]),
                'no_address' => __('Este contacto no le ha escrito a esta cuenta de :channel.', ['channel' => $name]),
                'unsubscribed', 'suppressed', 'excluded_registry' => __('Este contacto pidió no recibir más mensajes.'),
                default => self::denied($reason),
            };
        }

        return match ($reason) {
            'channel_no_marketing' => __('Messenger e Instagram no permiten mensajes de marketing.'),
            'outside_24h_window' => __('Pasaron más de 24 horas desde el último mensaje del cliente: solo puedes enviar una plantilla aprobada.'),
            'no_consent' => __('El contacto no aceptó mensajes de marketing por WhatsApp: usa una plantilla de utilidad.'),
            'unsubscribed', 'suppressed', 'excluded_registry' => __('Este número pidió no recibir más mensajes.'),
            'channel_unavailable' => __('La cuenta de WhatsApp no está activa.'),
            'quality_low' => __('La calidad del número está baja: Meta limita los envíos de marketing.'),
            'unofficial_marketing' => __('Este número no oficial no puede enviar marketing.'),
            'no_address' => __('El contacto no tiene un número válido.'),
            'template_error' => __('La plantilla no se pudo armar: revisa sus variables.'),
            default => __('No se pudo enviar el mensaje (:reason).', ['reason' => $reason]),
        };
    }
}
