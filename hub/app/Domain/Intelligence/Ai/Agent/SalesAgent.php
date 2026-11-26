<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent;

use App\Domain\Bookings\Chat\ChatBookingIds;
use App\Domain\Capture\Listeners\WhatsAppLinkInbound;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Agent\Models\AgentTurn;
use App\Domain\Intelligence\Ai\Agent\Support\AgentRun;
use App\Domain\Intelligence\Ai\Agent\Support\PromptBuilder;
use App\Domain\Intelligence\Ai\Agent\Support\ReplyGuard;
use App\Domain\Intelligence\Ai\Agent\Tools\AgentToolbox;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\WhatsAppInteractive;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Support\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agente de ventas con IA de la bandeja (M11, inteligencia.md §6.7).
 *
 * Por cada mensaje de la persona: revisa las guardas (baja, límite por hora,
 * reglas de traspaso, horario, ventana de 24 h), llama al modelo con sus
 * herramientas y **valida la respuesta** con `ReplyGuard` antes de usarla: una
 * cifra que no salió de las herramientas, las políticas o la persona, o un precio
 * o stock sin consulta en vivo, se corrige una vez; si persiste, en `auto` pasa a
 * un humano sin enviar nada y en `assist` deja la sugerencia marcada para revisar.
 *
 * Modos: `auto` responde (mensaje de servicio, solo con la ventana abierta),
 * `assist` deja una sugerencia cifrada para el agente humano, `off` no hace nada.
 * Cada turno queda en `ai_agent_turns`.
 *
 * Agenda en el chat (M18): si una herramienta armó horarios para tocar, la respuesta
 * sale como botones o lista de WhatsApp; los toques los atiende `ChatBookingReply`, no
 * el modelo. Calificación: si la derivación manda a la web, la respuesta lleva el enlace.
 */
final class SalesAgent implements ConversationAgent
{
    public const MAX_TURNS_PER_HOUR = 30;

    private const HISTORY = 20;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly AgentToolbox $toolbox,
        private readonly PromptBuilder $prompts,
        private readonly ReplyGuard $guard,
        private readonly MessageDispatcher $dispatcher,
        private readonly InboundClassifier $classifier,
    ) {}

    public function onInbound(Conversation $conversation, Message $inbound): void
    {
        $settings = AgentSettings::current($conversation->workspace_id);
        $mode = (string) ($conversation->ai_mode ?: 'off');
        if (! $settings->enabled || ! in_array($mode, ['assist', 'auto'], true) || $inbound->direction !== 'in' || $inbound->conversation_id !== $conversation->id) {
            return;
        }
        // El toque a un horario de la agenda lo atiende ChatBookingReply, y el «Sí, quiero» de recibir novedades de un
        // chat con atribución lo atiende WhatsAppLinkInbound: el agente no les contesta encima.
        $button = ((array) $inbound->payload)['button_payload'] ?? null;
        if (ChatBookingIds::is($button) || WhatsAppLinkInbound::isOptInButton($button)) {
            return;
        }
        $this->turn($conversation, $inbound, $mode, $settings);
    }

    public function suggest(Conversation $conversation): ?string
    {
        $settings = AgentSettings::current($conversation->workspace_id);
        if (! $settings->enabled) {
            return null;
        }
        $last = Message::query()->where('conversation_id', $conversation->id)->where('direction', 'in')->orderByDesc('created_at')->first();
        if ($last === null) {
            return null;
        }
        $turn = $this->turn($conversation, $last, 'assist', $settings);

        return $turn->status === 'suggested' ? $turn->suggestion : null;
    }

    /**
     * «Probar el agente»: la misma lógica sobre una conversación escrita en el panel,
     * con las herramientas reales de la tienda conectada y sin enviar nada.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     */
    public function sandbox(Workspace $workspace, array $history): AgentTurn
    {
        $settings = AgentSettings::current($workspace->id);
        $run = new AgentRun($workspace, $settings, self::site($workspace->id), null, null, true);

        return $this->respond($run, self::normalize($history), 'assist', null);
    }

    private function turn(Conversation $conversation, Message $inbound, string $mode, AgentSettings $settings): AgentTurn
    {
        $workspace = Workspace::query()->findOrFail($conversation->workspace_id);
        $contact = $conversation->contact_id !== null ? Contact::query()->find($conversation->contact_id) : null;
        $run = new AgentRun($workspace, $settings, self::site($workspace->id), $conversation, $contact);

        return $this->respond($run, $this->history($conversation, $inbound), $mode, $inbound);
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     */
    private function respond(AgentRun $run, array $history, string $mode, ?Message $inbound): AgentTurn
    {
        $started = microtime(true);
        $turn = AgentTurn::query()->create([
            'workspace_id' => $run->workspace->id, 'conversation_id' => $run->conversation?->id, 'inbound_message_id' => $inbound?->id,
            'mode' => $mode, 'is_test' => $run->isTest, 'status' => 'failed',
        ]);
        // Las reglas (baja, traspaso, alcance) miran solo el mensaje que se está atendiendo: el historial
        // une los entrantes seguidos y una «BAJA» de antes no debe bloquear la pregunta de ahora.
        $current = $inbound !== null ? trim((string) (((array) $inbound->payload)['text'] ?? '')) : null;
        $last = $current ?? ($history !== [] ? (string) end($history)['content'] : '');
        $customerTexts = array_values(array_map(fn (array $m) => (string) $m['content'], array_filter($history, fn (array $m) => $m['role'] === 'user')));
        $run->customerTexts = $customerTexts;

        $blocked = $this->guards($run, $turn, $mode, $last);
        if ($blocked !== null) {
            return $this->finish($turn, $started, $blocked);
        }

        $channel = $run->conversation !== null ? ChannelAccount::query()->find($run->conversation->channel_account_id)?->channel : null;
        $tools = $this->toolbox->for($run);
        $request = new AiRequest($run->workspace->id, 'sales_agent', $this->prompts->system($run->workspace, $run->settings, $channel ?? Channel::WhatsApp,
            array_map(fn (AiTool $t) => $t->name, $tools)), $history);
        $calls = [];
        $usage = ['in' => 0, 'out' => 0];
        $model = null;
        try {
            $result = $this->ai->withTools($request, $tools, max(2, min(10, (int) $run->settings->max_steps)));
            [$calls, $model] = [$result->toolCalls, $result->model];
            $usage = self::add($usage, $result);
            $reply = trim($result->text);
            $check = $this->guard->check($reply, $calls, $run->settings->policyTexts(), $customerTexts);
            $attempts = [$check];
            if ($run->handoffReason === null && ! $result->refused() && $reply !== '' && ! $check['ok']) {
                $correction = $check['needs_lookup']
                    ? 'Antes de decir un precio o si hay unidades, consulta get_product (o arma el carrito con create_cart_link). Reescribe la respuesta con esos datos.'
                    : 'Estas cifras no salen de tus herramientas ni de las políticas: '.implode(', ', $check['unknown']).'. Reescribe la respuesta usando solo datos de las herramientas; si no tienes el dato, dilo.';
                $result = $this->ai->withTools($request->followUp($reply, $correction), $tools, max(2, min(10, (int) $run->settings->max_steps)));
                $calls = [...$calls, ...$result->toolCalls];
                $usage = self::add($usage, $result);
                $reply = trim($result->text);
                $check = $this->guard->check($reply, $calls, $run->settings->policyTexts(), $customerTexts);
                $attempts[] = $check;
            }
            $turn->forceFill(['tool_calls' => self::summarize($calls), 'validation' => ['attempts' => $attempts, 'ok' => $check['ok']], 'model' => $model,
                'tokens_in' => $usage['in'], 'tokens_out' => $usage['out'], 'cart_id' => $run->cartId]);

            if ($run->handoffReason !== null) {
                return $this->finish($turn, $started, $this->handoff($run, $turn, $mode, $run->handoffReason, $reply !== '' && $check['ok'] ? $reply : null));
            }
            if ($result->refused() || $reply === '') {
                return $this->finish($turn, $started, $this->handoff($run, $turn, $mode, $result->refused() ? 'refusal' : 'empty_reply'));
            }
            if (! $check['ok']) {
                if ($mode === 'assist') {
                    $turn->forceFill(['status' => 'suggested', 'suggestion' => $reply, 'flagged' => true, 'review_status' => 'needs_fix']);

                    return $this->finish($turn, $started, null);
                }

                return $this->finish($turn, $started, $this->handoff($run, $turn, $mode, 'unverified_numbers'));
            }
        } catch (AiUnavailable $e) {
            $turn->forceFill(['validation' => ['error' => $e->reason], 'tool_calls' => self::summarize($calls), 'model' => $model]);

            return $this->finish($turn, $started, $mode === 'auto' ? $this->handoff($run, $turn, $mode, 'ai_unavailable') : 'failed');
        }

        // La derivación a la web de la calificación: el enlace va siempre, aunque el modelo no lo escriba.
        if ($run->requiredLink !== null && ! str_contains($reply, $run->requiredLink)) {
            $reply = rtrim($reply)."\n".$run->requiredLink;
        }
        if ($mode === 'assist') {
            // La persona del equipo envía texto: las opciones para tocar van escritas.
            $suggestion = $run->interactive !== null ? WhatsAppInteractive::asText(WhatsAppInteractive::withBody($run->interactive, $reply)) : $reply;
            $turn->forceFill(['status' => 'suggested', 'suggestion' => $suggestion]);

            return $this->finish($turn, $started, null);
        }

        return $this->finish($turn, $started, $this->send($run, $turn, $reply) ? null : $this->handoff($run, $turn, $mode, 'send_denied'));
    }

    /** @return string|null el estado si el turno no llega al modelo */
    private function guards(AgentRun $run, AgentTurn $turn, string $mode, string $text): ?string
    {
        if (trim($text) === '') {
            $turn->forceFill(['handoff_reason' => 'no_text']);

            return 'blocked';
        }
        $classification = $this->classifier->classify($run->workspace->id, $text);
        if ($classification->optOut()) {
            $turn->forceFill(['handoff_reason' => 'optout']);

            return 'blocked';
        }
        if ($run->conversation !== null) {
            $recent = AgentTurn::query()->where('conversation_id', $run->conversation->id)->where('id', '!=', $turn->id)
                ->where('created_at', '>=', now()->subHour())->count();
            if ($recent >= self::MAX_TURNS_PER_HOUR) {
                return $this->handoff($run, $turn, $mode, 'rate_limit');
            }
        }
        $rules = array_replace(AgentSettings::defaultHandoff(), (array) ($run->settings->handoff_rules ?? []));
        $normalized = ' '.trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(Str::ascii($text)))).' ';
        foreach ((array) ($rules['keywords'] ?? []) as $keyword) {
            $k = trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', strtolower(Str::ascii((string) $keyword))));
            if ($k !== '' && str_contains($normalized, ' '.$k.' ')) {
                return $this->handoff($run, $turn, $mode, 'keyword');
            }
        }
        if (in_array($classification->intent, (array) ($rules['intents'] ?? []), true)) {
            return $this->handoff($run, $turn, $mode, 'intent:'.$classification->intent);
        }
        // En «Probar el agente» no aplica el horario: se prueba a cualquier hora.
        if (! $run->isTest && $this->humansOnDuty($run, (array) ($rules['hours'] ?? []))) {
            return $this->handoff($run, $turn, $mode, 'business_hours');
        }
        if ($mode === 'auto' && $run->conversation !== null && ($run->conversation->window_expires_at === null || $run->conversation->window_expires_at->isPast())) {
            return $this->handoff($run, $turn, $mode, 'window_closed');
        }

        return null;
    }

    /**
     * Con `hours.mode = out_of_hours` el agente solo atiende fuera del horario del
     * equipo (el horario de atención de Tu negocio, `BusinessHours`): dentro de él, pasa
     * la conversación a las personas.
     *
     * @param  array<string, mixed>  $hours  mode, days (1 = lunes … 7 = domingo), start, end (HH:MM)
     */
    private function humansOnDuty(AgentRun $run, array $hours): bool
    {
        if (($hours['mode'] ?? 'always') !== 'out_of_hours') {
            return false;
        }
        // El horario de atención de Tu negocio manda; sin él, los días y horas guardados en el agente.
        $hours = BusinessHours::of($run->workspace) ?? $hours;
        $now = CarbonImmutable::now($run->workspace->timezone ?: 'America/Bogota');
        $days = array_map('intval', (array) ($hours['days'] ?? [1, 2, 3, 4, 5]));
        $time = $now->format('H:i');

        return in_array($now->dayOfWeekIso, $days, true) && $time >= (string) ($hours['start'] ?? '08:00') && $time < (string) ($hours['end'] ?? '18:00');
    }

    /** Pasa a un humano: la conversación deja de tener IA y queda el motivo. */
    private function handoff(AgentRun $run, AgentTurn $turn, string $mode, string $reason, ?string $closing = null): string
    {
        $turn->forceFill(['handoff_reason' => mb_substr($reason, 0, 64)]);
        if ($run->conversation === null) {
            return 'handoff';
        }
        $run->conversation->forceFill(['ai_mode' => 'off', 'handoff_reason' => mb_substr($reason, 0, 64)])->save();
        DB::table('conversation_events')->insert([
            'workspace_id' => $run->workspace->id, 'conversation_id' => $run->conversation->id, 'kind' => 'handoff', 'actor' => 'ai',
            'data' => json_encode(['reason' => $reason, 'turn' => $turn->publicId()]), 'created_at' => now(),
        ]);
        $open = $run->conversation->window_expires_at !== null && $run->conversation->window_expires_at->isFuture();
        if ($mode === 'auto' && $open && ! in_array($reason, ['optout', 'rate_limit', 'window_closed'], true)) {
            $this->send($run, $turn, $closing ?? __('Te comunico con una persona de nuestro equipo; en breve te responde.'), ':handoff');
        }

        return 'handoff';
    }

    private function send(AgentRun $run, AgentTurn $turn, string $text, string $keySuffix = ''): bool
    {
        $conversation = $run->conversation;
        if ($conversation === null) {
            return false;
        }
        $account = ChannelAccount::query()->find($conversation->channel_account_id);
        $channel = $account !== null ? $account->channel : Channel::WhatsApp;
        $context = ['text' => $text];
        // Horarios para tocar (agenda): botones o lista de WhatsApp con la respuesta como cuerpo; el texto lleva las opciones.
        if ($keySuffix === '' && $run->interactive !== null && $channel === Channel::WhatsApp) {
            $interactive = WhatsAppInteractive::withBody($run->interactive, $text);
            $context = ['text' => WhatsAppInteractive::asText($interactive), 'interactive' => $interactive];
        }
        // Sin humanAgent: la IA nunca usa la etiqueta HUMAN_AGENT de Messenger (solo responde dentro de las 24 h).
        $result = $this->dispatcher->dispatch(new OutboundMessage(
            $run->workspace->id, $conversation->contact_id, $channel instanceof Channel ? $channel : Channel::from((string) $channel), MessageKind::Service, 'inbox',
            sourceId: $conversation->id, context: $context, idempotencyKey: 'agent:'.$turn->id.$keySuffix,
            channelAccountId: $conversation->channel_account_id,
        ));
        if (! $result->sent() || $result->messageId === null) {
            return false;
        }
        Message::query()->whereKey($result->messageId)->update(['conversation_id' => $conversation->id]);
        $conversation->forceFill(['last_outbound_at' => now()])->save();
        if ($turn->reply_message_id === null) {
            $turn->forceFill(['reply_message_id' => $result->messageId]);
        }

        return true;
    }

    private function finish(AgentTurn $turn, float $started, ?string $status): AgentTurn
    {
        if ($status !== null) {
            $turn->forceFill(['status' => $status]);
        } elseif ($turn->status === 'failed') {
            $turn->forceFill(['status' => 'replied']);
        }
        $turn->forceFill(['latency_ms' => (int) round((microtime(true) - $started) * 1000)])->save();

        return $turn;
    }

    /** @return list<array{role: 'user'|'assistant', content: string}> */
    private function history(Conversation $conversation, Message $inbound): array
    {
        $rows = Message::query()->where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('created_at', '<', $inbound->created_at)->orWhere('id', $inbound->id))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::HISTORY)->get()->reverse();
        $out = [];
        foreach ($rows as $m) {
            $text = trim((string) (((array) $m->payload)['text'] ?? ''));
            if ($text !== '') {
                $out[] = ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'content' => $text];
            }
        }

        return self::normalize($out);
    }

    /**
     * Une los mensajes seguidos del mismo rol y empieza por la persona.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private static function normalize(array $messages): array
    {
        /** @var list<array{role: 'user'|'assistant', content: string}> $out */
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $content = mb_substr(trim((string) $m['content']), 0, 2000);
            if ($content === '' || ($out === [] && $role === 'assistant')) {
                continue;
            }
            $lastIndex = count($out) - 1;
            if ($lastIndex >= 0 && $out[$lastIndex]['role'] === $role) {
                $out[$lastIndex] = ['role' => $role, 'content' => $out[$lastIndex]['content']."\n".$content];
            } else {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }

    /**
     * @param  array{in: int, out: int}  $usage
     * @return array{in: int, out: int}
     */
    private static function add(array $usage, AiResult $result): array
    {
        return ['in' => $usage['in'] + $result->usage->inputTokens, 'out' => $usage['out'] + $result->usage->outputTokens];
    }

    /**
     * Registro de herramientas, recortado (entrada y salida, sin datos de la persona más allá del chat).
     *
     * @param  list<array{name: string, input: array<string, mixed>, output: array<string, mixed>|null, error: string|null}>  $calls
     * @return list<array<string, mixed>>
     */
    private static function summarize(array $calls): array
    {
        return array_map(fn (array $c) => [
            'name' => $c['name'],
            'input' => $c['input'],
            'output' => $c['output'] !== null ? mb_substr((string) json_encode($c['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1500) : null,
            'error' => $c['error'] !== null ? mb_substr($c['error'], 0, 300) : null,
        ], array_slice($calls, 0, 20));
    }

    private static function site(string $workspaceId): ?Site
    {
        return Site::query()->where('workspace_id', $workspaceId)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->first();
    }
}
