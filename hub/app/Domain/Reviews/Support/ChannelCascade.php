<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Support;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Envío con canal de respaldo para las solicitudes de reseña y las encuestas
 * (M15): prueba los canales en orden con MessageDispatcher y la clave
 * `{prefijo}:{canal}`. Si la política niega uno, sigue con el siguiente; si
 * aplaza, se detiene (quien llama reprograma para `until` y vuelve con la misma
 * clave); un error transitorio también se reintenta después.
 */
final class ChannelCascade
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /**
     * @param  list<string>  $channels
     * @param  array<string, string|null>  $templates  canal → ID público o ULID de la plantilla elegida
     * @param  array<string, mixed>  $context
     * @return array{status: 'sent', channel: string, message_id: string|null}|array{status: 'deferred', until: CarbonImmutable}|array{status: 'skipped', reason: string}
     */
    public function send(Workspace $workspace, string $contactId, array $channels, array $templates, ?string $defaultEmailKey, MessageKind $kind, string $sourceId, array $context, string $keyPrefix, CarbonImmutable $now): array
    {
        $reason = 'no_channel';
        foreach ($channels as $name) {
            $channel = Channel::tryFrom($name);
            if ($channel === null) {
                continue;
            }
            $templateId = $this->template($workspace, $channel, $templates[$channel->value] ?? null, $defaultEmailKey);
            if ($templateId === null) {
                $reason = 'no_template';

                continue;
            }
            try {
                $result = $this->dispatcher->dispatch(new OutboundMessage(
                    workspaceId: $workspace->id,
                    contactId: $contactId,
                    channel: $channel,
                    kind: $kind,
                    sourceType: 'system',
                    sourceId: $sourceId,
                    templateId: $templateId,
                    context: $context,
                    idempotencyKey: $keyPrefix.':'.$channel->value,
                ));
            } catch (Throwable $e) {
                report($e);

                return ['status' => 'deferred', 'until' => $now->addMinutes(15)];
            }
            if ($result->status === DispatchResult::DEFERRED) {
                return ['status' => 'deferred', 'until' => $result->until ?? $now->addHour()];
            }
            if ($result->sent()) {
                return ['status' => 'sent', 'channel' => $channel->value, 'message_id' => $result->messageId];
            }
            $reason = (string) $result->reason;
        }

        return ['status' => 'skipped', 'reason' => mb_substr($reason, 0, 40)];
    }

    private function template(Workspace $workspace, Channel $channel, ?string $chosen, ?string $defaultEmailKey): ?string
    {
        if ($chosen !== null && $chosen !== '') {
            return TemplateRef::resolve($workspace->id, $channel->value, $chosen, null);
        }
        if ($channel === Channel::Email && $defaultEmailKey !== null) {
            // La plantilla de la biblioteca de M08 (se instala una vez y luego se puede editar).
            TemplateLibrary::install($workspace, $defaultEmailKey);

            return TemplateRef::resolve($workspace->id, 'email', null, $defaultEmailKey);
        }

        return null;
    }
}
