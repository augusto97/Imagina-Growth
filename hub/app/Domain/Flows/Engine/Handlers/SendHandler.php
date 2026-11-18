<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\NodeTypes;
use App\Domain\Flows\Support\TemplateContext;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use Throwable;

/**
 * Envía WhatsApp, correo o SMS por MessageDispatcher con la clave
 * `run:{run}:{nodo}:{intento}`: un reintento del job devuelve `duplicate` y no
 * reenvía. Si la política aplaza, el nodo queda `deferred` y se vuelve a llamar
 * con la misma clave en `until`. Si niega, sigue por la rama `failed` (o por la
 * salida normal si no hay). El grupo de control no recibe nada.
 */
final class SendHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $channel = Channel::from((string) NodeTypes::channelOf($node['type']));
        $config = $node['config'];
        $templateId = TemplateRef::resolve($state->workspace->id, $channel->value, $config['template_id'] ?? null, $config['template_key'] ?? null);
        if ($templateId === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_template'], fallback: true);
        }
        $kind = MessageKind::tryFrom((string) ($config['kind'] ?? 'marketing')) ?? MessageKind::Marketing;

        $to = null;
        if ($state->isTest()) {
            $to = $channel === Channel::Email ? ($state->run->context['_test']['email'] ?? null) : ($state->run->context['_test']['phone'] ?? null);
            if (! is_string($to) || $to === '') {
                return Outcome::next('failed', 'failed', ['reason' => 'no_test_recipient'], fallback: true);
            }
        }

        try {
            $result = app(MessageDispatcher::class)->dispatch(new OutboundMessage(
                workspaceId: $state->workspace->id,
                contactId: $state->run->contact_id,
                channel: $channel,
                kind: $kind,
                sourceType: 'flow',
                sourceId: $state->flow->id,
                templateId: $templateId,
                context: TemplateContext::build($state),
                idempotencyKey: $state->idempotencyKey($node['id']),
                flowId: $state->flow->id,
                to: $to,
                isTest: $state->isTest(),
            ));
        } catch (Throwable $e) {
            report($e);

            return Outcome::next('failed', 'failed', ['reason' => 'error', 'error' => mb_substr($e->getMessage(), 0, 300)], fallback: true);
        }

        return match ($result->status) {
            DispatchResult::QUEUED, DispatchResult::DUPLICATE => Outcome::next(record: 'sent', details: ['message_id' => $result->messageId, 'template_id' => $templateId, 'channel' => $channel->value]),
            DispatchResult::DEFERRED => Outcome::deferred($result->until ?? $state->now->addHour(), (string) $result->reason),
            default => Outcome::next('failed', 'denied', ['reason' => $result->reason, 'channel' => $channel->value], fallback: true),
        };
    }
}
