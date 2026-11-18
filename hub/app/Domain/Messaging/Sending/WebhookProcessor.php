<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sending;

use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Channels\Data\StatusUpdate;
use App\Domain\Messaging\Channels\Data\WebhookBatch;
use App\Domain\Messaging\Inbound\InboundHandler;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\Suppression;
use App\Domain\Messaging\Models\WaTemplate;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Aplica lo que trae un webhook ya verificado (hub/CLAUDE.md §8): estados de
 * los mensajes (cada evento message.* una sola vez), rebotes y quejas a la
 * supresión, mensajes entrantes, estado de las plantillas y calidad del número.
 * Los webhooks llegan sin espacio de trabajo: se busca por el ID del proveedor
 * y se trabaja dentro del contexto del mensaje o la cuenta.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly MessageEvents $events,
        private readonly InboundHandler $inbound,
        private readonly UnsubscribeService $unsubscribe,
        private readonly SendPolicy $policy,
    ) {}

    /** @param list<string> $providers proveedores de este webhook (para buscar la cuenta) */
    public function apply(WebhookBatch $batch, array $providers, ?ChannelAccount $account = null): void
    {
        foreach ($batch->statuses as $item) {
            $this->status($item['update'], $providers);
        }
        foreach ($batch->inbound as $item) {
            $target = $account ?? $this->account($providers, $item['account']);
            if ($target !== null && $target->isActive()) {
                $this->inbound->handle($target, $item['message']);
            }
        }
        foreach ($batch->templates as $item) {
            $query = WaTemplate::withoutWorkspaceScope();
            $template = $item['meta_template_id'] !== null
                ? $query->where('meta_template_id', $item['meta_template_id'])->first()
                : ($item['name'] !== null ? $query->where('name', $item['name'])->where('language', (string) $item['language'])->first() : null);
            $template?->forceFill(['status' => $item['status'], 'rejected_reason' => $item['reason'], 'synced_at' => now()])->save();
        }
        foreach ($batch->quality as $item) {
            $target = $account ?? $this->account($providers, $item['account']);
            if ($target === null && isset($item['display_phone_number'])) {
                $digits = preg_replace('/\D/', '', (string) $item['display_phone_number']);
                $target = ChannelAccount::withoutWorkspaceScope()->where('provider', 'meta_cloud')->get()
                    ->first(fn (ChannelAccount $a) => preg_replace('/\D/', '', (string) $a->identity) === $digits);
            }
            if ($target !== null) {
                $target->forceFill(array_filter(['quality_rating' => $item['quality'], 'messaging_limit_tier' => $item['tier']], fn ($v) => $v !== null))->save();
                if ($item['quality'] === 'RED') {
                    Log::warning('igr.messaging.quality_red', ['account' => $target->id]);
                }
            }
        }
    }

    /** @param list<string> $providers */
    private function account(array $providers, ?string $externalId): ?ChannelAccount
    {
        return $externalId === null ? null : ChannelAccount::withoutWorkspaceScope()->whereIn('provider', $providers)->where('external_id', $externalId)->first();
    }

    /** @param list<string> $providers */
    private function status(StatusUpdate $update, array $providers): void
    {
        if ($update->providerMessageId === '') {
            return;
        }
        $message = Message::withoutWorkspaceScope()->whereIn('provider', $providers)->where('provider_message_id', $update->providerMessageId)->where('direction', 'out')->first();
        if ($message === null) {
            return;
        }
        $workspace = Workspace::query()->find($message->workspace_id);
        if ($workspace === null) {
            return;
        }
        $this->current->run($workspace, fn () => $this->applyStatus($workspace, $message, $update));
    }

    private function applyStatus(Workspace $workspace, Message $message, StatusUpdate $update): void
    {
        $at = $update->at;
        switch ($update->status) {
            case 'sent':
                if (in_array($message->status, ['queued', 'sending'], true)) {
                    $message->forceFill(['status' => 'sent', 'sent_at' => $message->sent_at ?? $at])->save();
                }
                break;
            case 'delivered':
                $first = $message->delivered_at === null;
                $message->forceFill(['delivered_at' => $message->delivered_at ?? $at, 'status' => $this->advance($message, 'delivered')])->save();
                if ($first) {
                    $this->events->emit($workspace, $message, 'message.delivered', [], $at);
                }
                break;
            case 'read':
                $first = $message->read_at === null;
                $message->forceFill(['read_at' => $message->read_at ?? $at, 'delivered_at' => $message->delivered_at ?? $at, 'status' => $this->advance($message, 'read')])->save();
                if ($first) {
                    $this->events->emit($workspace, $message, 'message.read', [], $at);
                }
                break;
            case 'opened':
                // opened_at solo cuenta aperturas reales: las de Apple MPP no sirven para atribuir.
                $payload = $message->payload ?? [];
                $seen = $update->isMpp ? ! empty($payload['mpp_open']) : $message->opened_at !== null;
                if ($update->isMpp) {
                    $payload['mpp_open'] = true;
                    $message->forceFill(['payload' => $payload])->save();
                } elseif ($message->opened_at === null) {
                    $message->forceFill(['opened_at' => $at])->save();
                }
                if (! $seen) {
                    $this->events->emit($workspace, $message, 'message.opened', ['is_mpp' => $update->isMpp], $at);
                }
                break;
            case 'failed':
                if (! in_array($message->status, Message::FINAL, true)) {
                    $message->forceFill(['status' => 'failed', 'failed_reason' => $update->error, 'error_code' => $update->errorCode])->save();
                    $this->releaseFrequency($workspace, $message);
                    $this->events->emit($workspace, $message, 'message.failed', ['reason' => $update->error !== null ? mb_substr($update->error, 0, 500) : null, 'error_code' => $update->errorCode], $at);
                    if ($update->errorCode === '131049') {
                        Log::info('igr.messaging.meta_marketing_limit', ['message' => $message->id]); // tope de Meta por usuario: no se reintenta
                    }
                    if ($update->errorCode === '131050' && $message->contact_id !== null) {
                        // La persona detuvo el marketing de esta empresa desde WhatsApp: se revoca solo el marketing
                        // de WhatsApp (los avisos transaccionales siguen). Sin evento extra: ya salió message.failed.
                        $this->unsubscribe->unsubscribe($workspace, $message->contact_id, 'whatsapp', 'channel', 'meta_stop', null, ['error_code' => '131050']);
                    }
                }
                break;
            case 'bounced':
                $message->forceFill(['status' => 'bounced', 'failed_reason' => $update->error, 'error_code' => $update->errorCode])->save();
                $this->events->emit($workspace, $message, 'message.bounced', ['bounce_type' => $update->bounceType, 'reason' => $update->error !== null ? mb_substr($update->error, 0, 500) : null], $at);
                if ($update->bounceType === 'hard') {
                    $this->suppress($workspace, $message, $update, 'bounce');
                }
                break;
            case 'complained':
                $this->events->emit($workspace, $message, 'message.complained', [], $at);
                $this->suppress($workspace, $message, $update, 'complaint');
                if ($message->contact_id !== null) {
                    $this->unsubscribe->unsubscribe($workspace, $message->contact_id, 'email', 'channel', 'complaint', $message);
                }
                break;
        }
    }

    /** Estado más avanzado entre el actual y el nuevo (un webhook tardío no retrocede). */
    private function advance(Message $message, string $status): string
    {
        if (in_array($message->status, Message::FINAL, true)) {
            return $message->status;
        }

        return (Message::PROGRESS[$status] ?? 0) > (Message::PROGRESS[$message->status] ?? 0) ? $status : $message->status;
    }

    private function suppress(Workspace $workspace, Message $message, StatusUpdate $update, string $reason): void
    {
        $bidx = null;
        if ($update->recipient !== null && filter_var($update->recipient, FILTER_VALIDATE_EMAIL) !== false) {
            $bidx = BlindIndex::email($workspace->id, $update->recipient);
        } elseif ($message->contact_id !== null) {
            $contact = Contact::query()->find($message->contact_id);
            $bidx = $message->channel->value === 'email' ? $contact?->email_bidx : $contact?->phone_bidx;
        }
        if ($bidx !== null) {
            Suppression::add($workspace->id, $message->channel->value, $bidx, $reason);
            Log::info('igr.messaging.suppressed', ['reason' => $reason, 'recipient' => Pii::mask((string) $update->recipient)]);
        }
    }

    private function releaseFrequency(Workspace $workspace, Message $message): void
    {
        $contact = $message->contact_id !== null ? Contact::query()->find($message->contact_id) : null;
        $this->policy->release(
            new PolicyRequest($workspace, $contact, $message->channel, $message->kind, $message->source_type),
            CarbonImmutable::instance($message->queued_at ?? now()),
        );
    }
}
