<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Support\TemplateContext;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Throwable;

/**
 * Aviso al equipo por correo o WhatsApp (plantilla `team_alert` de M08) con el
 * texto del nodo y el enlace al perfil del contacto. Va a los dueños del
 * espacio de trabajo y a los destinos escritos en el nodo; es de utilidad, no
 * de marketing. El grupo de control no genera avisos.
 */
final class NotifyTeamHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $c = $node['config'];
        $channel = Channel::tryFrom((string) ($c['channel'] ?? 'email')) ?? Channel::Email;
        $templateId = TemplateRef::resolve($state->workspace->id, $channel->value, $c['template_id'] ?? null, $c['template_key'] ?? TemplateRef::TEAM_ALERT);
        if ($templateId === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_template'], fallback: true);
        }
        $recipients = $this->recipients($state, $channel, $c);
        if ($recipients === []) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_recipients'], fallback: true);
        }
        $contactUrl = rtrim((string) config('app.url'), '/').'/w/'.$state->workspace->publicId().'/contactos/'.($state->contact?->publicId() ?? '');
        $context = TemplateContext::build($state) + ['aviso' => [
            'titulo' => $state->flow->name,
            // `resumen` y `detalle` son los nombres de la plantilla team_alert de M08; `texto` queda por compatibilidad.
            'resumen' => mb_substr((string) ($c['message'] ?? ''), 0, 500),
            'detalle' => mb_substr((string) ($c['message'] ?? ''), 0, 500),
            'texto' => mb_substr((string) ($c['message'] ?? ''), 0, 500),
            'url' => $contactUrl,
        ]];
        $sent = 0;
        foreach ($recipients as $i => $to) {
            try {
                $result = app(MessageDispatcher::class)->dispatch(new OutboundMessage(
                    workspaceId: $state->workspace->id,
                    contactId: $state->run->contact_id,
                    channel: $channel,
                    kind: MessageKind::Utility,
                    sourceType: 'flow',
                    sourceId: $state->flow->id,
                    templateId: $templateId,
                    context: $context,
                    idempotencyKey: $state->idempotencyKey($node['id'], (string) $i),
                    flowId: $state->flow->id,
                    to: $to,
                    isTest: $state->isTest(),
                ));
                $sent += $result->sent() ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent > 0
            ? Outcome::next(record: 'sent', details: ['recipients' => count($recipients), 'sent' => $sent])
            : Outcome::next('failed', 'failed', ['reason' => 'not_sent'], fallback: true);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private function recipients(RunState $state, Channel $channel, array $c): array
    {
        if ($state->isTest()) {
            $to = $channel === Channel::Email ? ($state->run->context['_test']['email'] ?? null) : ($state->run->context['_test']['phone'] ?? null);

            return is_string($to) && $to !== '' ? [$to] : [];
        }
        $list = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($c['recipients'] ?? []))));
        if ($channel === Channel::Email && ($c['to_owners'] ?? true) === true) {
            $owners = $state->workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->pluck('email')->all();
            $list = [...$list, ...array_map('strval', $owners)];
        }

        return array_values(array_unique(array_slice($list, 0, 10)));
    }
}
