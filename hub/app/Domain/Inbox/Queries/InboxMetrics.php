<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Domain\Inbox\Actions\AssignConversation;
use App\Domain\Inbox\Models\ConversationEvent;
use App\Domain\Messaging\Models\Message;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas de la bandeja (M11) en una ventana de días, desde `conversation_events`:
 * primera respuesta (del inicio del ciclo a la primera respuesta humana),
 * resolución (del inicio del ciclo al cierre), conversaciones y mensajes por
 * agente, ventas atribuidas a mensajes de la bandeja (clic en un enlace que
 * mandó esa persona, `orders.attributed_message_id`) y traspasos de la IA.
 */
final class InboxMetrics
{
    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $events = ConversationEvent::query()->where('created_at', '>=', $from)->get();
        $byKind = $events->groupBy('kind');
        $first = self::seconds($byKind->get('first_response'), 'seconds');
        $resolution = self::seconds(($byKind->get('status') ?? collect())->filter(fn (ConversationEvent $e) => ($e->data['to'] ?? null) === 'closed'), 'resolution_seconds');

        // Ventas: pedidos atribuidos a un mensaje de la bandeja, con su autor.
        $authors = [];
        foreach ($byKind->get('message') ?? collect() as $e) {
            $id = PrefixedId::decode(Message::ID_PREFIX, (string) ($e->data['message'] ?? ''));
            if ($id !== null) {
                $authors[$id] = $e->actor;
            }
        }
        $orders = DB::table('orders as o')->join('messages as m', 'm.id', '=', 'o.attributed_message_id')
            ->where('o.workspace_id', $workspace->id)->where('m.source_type', 'inbox')->where('o.created_at', '>=', $from)
            ->where('o.is_paid', true)->get(['o.id', 'o.total_ws', 'o.attributed_message_id']);
        $salesBy = [];
        foreach ($orders as $o) {
            $actor = $authors[(string) $o->attributed_message_id] ?? 'unknown';
            $salesBy[$actor] ??= ['orders' => 0, 'revenue' => '0'];
            $salesBy[$actor]['orders']++;
            $salesBy[$actor]['revenue'] = Decimal::add($salesBy[$actor]['revenue'], (string) $o->total_ws);
        }

        $agents = AssignConversation::agents($workspace->id);
        $names = User::query()->whereIn('id', $agents)->pluck('name', 'id')->all();
        $rows = [];
        foreach ($agents as $userId) {
            $mine = $events->where('actor', $userId);
            $assigned = ($byKind->get('assigned') ?? collect())->filter(fn (ConversationEvent $e) => ($e->data['user'] ?? null) === PrefixedId::encode(User::ID_PREFIX, $userId));
            $rows[] = [
                'id' => PrefixedId::encode(User::ID_PREFIX, $userId),
                'name' => (string) ($names[$userId] ?? ''),
                'conversations' => $assigned->pluck('conversation_id')->merge($mine->where('kind', 'message')->pluck('conversation_id'))->unique()->count(),
                'messages' => $mine->where('kind', 'message')->count(),
                'resolved' => $mine->where('kind', 'status')->filter(fn (ConversationEvent $e) => ($e->data['to'] ?? null) === 'closed')->count(),
                'first_response' => self::seconds($mine->where('kind', 'first_response'), 'seconds'),
                'orders' => $salesBy[$userId]['orders'] ?? 0,
                'revenue' => $salesBy[$userId]['revenue'] ?? '0',
            ];
        }
        usort($rows, fn ($a, $b) => [$b['orders'], $b['messages']] <=> [$a['orders'], $a['messages']]);

        $aiTurns = Schema::hasTable('ai_agent_turns') ? DB::table('ai_agent_turns')->where('workspace_id', $workspace->id)->where('created_at', '>=', $from)
            ->selectRaw("COUNT(*) AS total, SUM(status = 'handoff') AS handoffs, SUM(status = 'replied') AS replied")->first() : null;
        $aiTotal = (int) ($aiTurns->total ?? 0);

        return [
            'days' => $days,
            'opened' => ($byKind->get('opened') ?? collect())->count(),
            'resolved' => ($byKind->get('status') ?? collect())->filter(fn (ConversationEvent $e) => ($e->data['to'] ?? null) === 'closed')->count(),
            'first_response' => $first,
            'resolution' => $resolution,
            'sales' => ['orders' => $orders->count(), 'revenue' => array_reduce(array_column($salesBy, 'revenue'), fn ($c, $v) => Decimal::add($c, $v), '0'), 'ai' => $salesBy['ai'] ?? ['orders' => 0, 'revenue' => '0']],
            'ai' => [
                'turns' => $aiTotal,
                'replied' => (int) ($aiTurns->replied ?? 0),
                'handoffs' => ($byKind->get('handoff') ?? collect())->where('actor', 'ai')->count(),
                'handoff_rate' => $aiTotal > 0 ? round((int) ($aiTurns->handoffs ?? 0) / $aiTotal, 4) : null,
                'messages' => ($byKind->get('message') ?? collect())->where('actor', 'ai')->count(),
            ],
            'agents' => $rows,
        ];
    }

    /**
     * @param  Collection<int, ConversationEvent>|null  $events
     * @return array{count: int, avg: int|null, median: int|null}
     */
    private static function seconds(?Collection $events, string $key): array
    {
        $values = ($events ?? collect())->map(fn (ConversationEvent $e) => $e->data[$key] ?? null)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->sort()->values();
        $n = $values->count();
        if ($n === 0) {
            return ['count' => 0, 'avg' => null, 'median' => null];
        }
        $median = $n % 2 === 1 ? $values[intdiv($n, 2)] : intdiv($values[$n / 2 - 1] + $values[$n / 2], 2);

        return ['count' => $n, 'avg' => (int) round((float) $values->avg()), 'median' => (int) $median];
    }
}
