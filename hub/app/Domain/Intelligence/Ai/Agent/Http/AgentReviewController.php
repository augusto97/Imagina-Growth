<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Http;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Ai\Agent\Models\AgentTurn;
use App\Domain\Messaging\Models\Conversation;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Revisión del agente: turnos, marcados, traspasos y ventas (M11, inteligencia.md §6.7). */
final class AgentReviewController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $ws = $this->current->get();
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,90'],
            'filter' => ['nullable', Rule::in(['all', 'flagged', 'handoff', 'replied', 'suggested', 'test'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $since = now()->subDays((int) ($data['days'] ?? 30));
        $base = AgentTurn::query()->where('created_at', '>=', $since);
        $live = (clone $base)->where('is_test', false);
        $total = (clone $live)->count();
        $byStatus = (clone $live)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $conversations = (clone $live)->whereNotNull('conversation_id')->distinct()->count('conversation_id');
        $handoffConversations = (clone $live)->where('status', 'handoff')->whereNotNull('conversation_id')->distinct()->count('conversation_id');
        $sales = DB::table('carts as c')->join('orders as o', 'o.id', '=', 'c.recovered_order_id')
            ->where('c.workspace_id', $ws->id)->where('c.token', 'like', 'agent\_%')->where('c.created_at', '>=', $since)
            ->selectRaw('count(*) as orders, coalesce(sum(o.total_ws), 0) as revenue')->first();

        $filter = $data['filter'] ?? 'all';
        $list = (clone $base)->when($filter === 'test', fn ($q) => $q->where('is_test', true), fn ($q) => $q->where('is_test', false))
            ->when($filter === 'flagged', fn ($q) => $q->where(fn ($w) => $w->where('flagged', true)->orWhere('review_status', 'wrong')))
            ->when(in_array($filter, ['handoff', 'replied', 'suggested'], true), fn ($q) => $q->where('status', $filter))
            ->orderByDesc('created_at')->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1));
        $contactNames = $this->contactNames(array_values(array_unique(array_filter(array_map(fn (AgentTurn $t) => (string) $t->conversation_id, $list->items())))));

        return response()->json([
            'summary' => [
                'turns' => $total, 'replied' => $byStatus['replied'] ?? 0, 'suggested' => $byStatus['suggested'] ?? 0,
                'handoff' => $byStatus['handoff'] ?? 0, 'blocked' => $byStatus['blocked'] ?? 0, 'failed' => $byStatus['failed'] ?? 0,
                'conversations' => $conversations, 'handoff_rate' => $conversations > 0 ? round($handoffConversations / $conversations, 4) : null,
                'flagged' => (clone $live)->where(fn ($w) => $w->where('flagged', true)->orWhere('review_status', 'wrong'))->count(),
                'orders' => (int) ($sales->orders ?? 0), 'revenue' => number_format((float) ($sales->revenue ?? 0), 2, '.', ''),
                'currency' => $ws->currency, 'days' => (int) ($data['days'] ?? 30),
            ],
            'data' => array_map(fn (AgentTurn $t) => self::row($t, $contactNames), $list->items()),
            'meta' => ['page' => $list->currentPage(), 'last_page' => $list->lastPage(), 'total' => $list->total()],
        ]);
    }

    public function show(string $turn): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $t = AgentTurn::findByPublicId($turn) ?? abort(404);
        $names = $this->contactNames($t->conversation_id !== null ? [$t->conversation_id] : []);

        return response()->json(self::row($t, $names) + [
            'suggestion' => $t->suggestion, 'tool_calls' => $t->tool_calls ?? [], 'validation' => $t->validation,
            'tokens_in' => $t->tokens_in, 'tokens_out' => $t->tokens_out,
        ]);
    }

    public function review(Request $request, string $turn): JsonResponse
    {
        Gate::authorize('ws.useInbox');
        $t = AgentTurn::findByPublicId($turn) ?? abort(404);
        $data = $request->validate(['review_status' => ['required', Rule::in(['ok', 'wrong', 'needs_fix'])]]);
        $t->forceFill(['review_status' => $data['review_status'], 'reviewed_by' => $request->user()?->getAuthIdentifier(), 'flagged' => $data['review_status'] !== 'ok' ? true : $t->flagged])->save();

        return response()->json(['id' => $t->publicId(), 'review_status' => $t->review_status, 'flagged' => $t->flagged]);
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function row(AgentTurn $t, array $names): array
    {
        return [
            'id' => $t->publicId(), 'created_at' => $t->created_at?->toIso8601String(), 'mode' => $t->mode, 'status' => $t->status,
            'is_test' => $t->is_test, 'handoff_reason' => $t->handoff_reason, 'flagged' => $t->flagged, 'review_status' => $t->review_status,
            'conversation_id' => $t->conversation_id !== null ? 'cnv_'.$t->conversation_id : null,
            'contact_name' => $t->conversation_id !== null ? ($names[$t->conversation_id] ?? null) : null,
            'tools' => array_values(array_unique(array_map(fn ($c) => (string) ($c['name'] ?? ''), (array) ($t->tool_calls ?? [])))),
            'validation_ok' => (bool) (($t->validation ?? [])['ok'] ?? true), 'has_cart' => $t->cart_id !== null, 'latency_ms' => $t->latency_ms,
        ];
    }

    /**
     * @param  list<string>  $conversationIds
     * @return array<string, string>
     */
    private function contactNames(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        $contacts = Conversation::query()->whereIn('id', $conversationIds)->pluck('contact_id', 'id');
        $names = Contact::query()->whereIn('id', $contacts->filter()->values())->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Contact $c) => [$c->id => trim(((string) $c->first_name).' '.mb_substr((string) $c->last_name, 0, 1)) ?: '—']);

        return $contacts->map(fn ($cid) => $names[$cid] ?? '—')->all();
    }
}
