<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Classifiers;

use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Events\InboundMessageReceived;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Sending\UnsubscribeService;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clasifica cada entrante que no fue una palabra de baja (cola `ai`) y deja la
 * intención en `conversations.intent`. Si la IA lee una baja con confianza de 0,8 o
 * más, revoca el marketing de ese canal por el mismo camino que las palabras clave
 * (`UnsubscribeService`, `via` keyword, con la evidencia `detected_by: ai`) y lo
 * confirma; con menos confianza nunca revoca: marca la conversación para que la
 * revise una persona (etiqueta `revisar_baja`).
 */
final class ClassifyInboundMessage implements ShouldQueue
{
    public const OPT_OUT_CONFIDENCE = 0.8;

    public const REVIEW_LABEL = 'revisar_baja';

    public string $queue = 'ai';

    public function __construct(
        private readonly InboundClassifier $classifier,
        private readonly UnsubscribeService $unsubscribe,
        private readonly CurrentWorkspace $current,
    ) {}

    public function handle(InboundMessageReceived $event): void
    {
        if ($event->optedOutByKeyword) {
            return;
        }
        $workspace = Workspace::query()->find($event->workspaceId);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, function () use ($workspace, $event): void {
            $message = Message::query()->find($event->messageId);
            $text = is_array($message?->payload) ? trim((string) ($message->payload['text'] ?? '')) : '';
            if ($message === null || $text === '') {
                return;
            }
            $result = $this->classifier->classify($workspace->id, $text);
            $conversation = $event->conversationId !== null ? Conversation::query()->find($event->conversationId) : null;
            $conversation?->forceFill(['intent' => $result->intent])->save();
            if (! $result->optOut() || $result->source !== 'ai') {
                return;
            }
            if ($result->confidence >= self::OPT_OUT_CONFIDENCE) {
                $this->optOut($workspace, $message, $conversation, $result->confidence);

                return;
            }
            if ($conversation !== null) {
                $labels = array_values(array_unique([...((array) $conversation->labels), self::REVIEW_LABEL]));
                $conversation->forceFill(['labels' => $labels])->save();
                self::event($workspace->id, $conversation->id, 'label', ['label' => self::REVIEW_LABEL, 'confidence' => $result->confidence]);
            }
        });
    }

    private function optOut(Workspace $workspace, Message $inbound, ?Conversation $conversation, float $confidence): void
    {
        $channel = $inbound->channel->value;
        $last = Message::query()->where('contact_id', $inbound->contact_id)->where('direction', 'out')->where('channel', $channel)
            ->where('kind', MessageKind::Marketing->value)->latest('created_at')->first();
        $revoked = $this->unsubscribe->unsubscribe($workspace, (string) $inbound->contact_id, $channel, 'channel', 'keyword', $last,
            ['detected_by' => 'ai', 'confidence' => round($confidence, 2), 'inbound_message_id' => $inbound->publicId()]);
        if ($conversation !== null) {
            self::event($workspace->id, $conversation->id, 'status', ['optout' => true, 'detected_by' => 'ai', 'revoked' => $revoked]);
        }
        if ($revoked === []) {
            return;
        }
        try {
            app(MessageDispatcher::class)->dispatch(new OutboundMessage(
                $workspace->id, (string) $inbound->contact_id, $inbound->channel, MessageKind::Service, 'system',
                context: ['text' => __('Listo, ya no te enviaremos promociones por este medio. Si fue un error, escríbenos.')],
                channelAccountId: $inbound->channel_account_id,
            ));
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.ai.optout_confirm_failed', ['message' => $inbound->id]);
        }
    }

    /** @param array<string, mixed> $data */
    private static function event(string $workspaceId, string $conversationId, string $kind, array $data): void
    {
        DB::table('conversation_events')->insert([
            'workspace_id' => $workspaceId, 'conversation_id' => $conversationId, 'kind' => $kind, 'actor' => 'ai',
            'data' => json_encode($data), 'created_at' => now(),
        ]);
    }
}
