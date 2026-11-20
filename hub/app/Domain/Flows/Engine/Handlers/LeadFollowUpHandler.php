<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Services\Leads\Assistant\LeadMessageWriter;
use App\Domain\Services\Leads\Assistant\LeadReach;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadTask;
use Throwable;

/**
 * «Seguimiento con IA» del lead (M09/M18, flujo 18): la IA escribe el toque con los
 * datos del lead (qué pidió, cantidad, ciudad, para cuándo, su último mensaje) para
 * que aporte algo, y sale por el despachador como **marketing** (consentimiento del
 * canal, horario legal y frecuencia; cumplimiento.md §4.1):
 *
 * - WhatsApp con la ventana de 24 h abierta: texto libre.
 * - WhatsApp con la ventana cerrada: la plantilla de marketing aprobada del nodo, de
 *   Ajustes de leads o la de `library_key` wa_lead_followup, con el texto en
 *   `seguimiento.mensaje`.
 * - Correo si WhatsApp no se puede (sin plantilla, sin consentimiento o negado).
 *
 * Un aplazamiento por horario o frecuencia vuelve con el mismo texto (cifrado en el
 * paso) y la misma clave. Si no sale por ningún canal, deja una tarea en «Hoy» para el
 * vendedor y sigue por «falló». Sin lead abierto el run termina. El grupo de control
 * no recibe nada; en una prueba el texto va al correo de quien prueba.
 */
final class LeadFollowUpHandler implements NodeHandler
{
    public function __construct(private readonly LeadMessageWriter $writer) {}

    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $lead = UpdateLeadBrief::openLead($state->run->contact_id);
        if ($lead === null) {
            return Outcome::exit('lead_closed');
        }
        $c = $node['config'];
        $step = is_int($c['step'] ?? null) ? $c['step'] : $state->attempt;
        /** @var list<'whatsapp'|'email'> $channels */
        $channels = array_values(array_intersect(['whatsapp', 'email'], (array) ($c['channels'] ?? ['whatsapp', 'email'])));
        $previous = $state->stepDetails();
        $text = self::decrypt($previous['draft'] ?? null);

        if ($state->isTest()) {
            return $this->test($state, $node, $lead, $text, $step);
        }

        $tries = $this->routes($state, $c, $channels);
        if ($tries === []) {
            $this->task($state, $node, $lead, $step, 'no_channel');

            return Outcome::next('failed', 'failed', ['reason' => 'no_channel'], fallback: true);
        }
        $text ??= $this->writer->write($state->workspace, $lead, 'followup', ['step' => $step, 'channel' => $tries[0]['channel']])['text'];
        $denied = [];
        foreach ($tries as $try) {
            try {
                $result = app(MessageDispatcher::class)->dispatch(self::message($state, $node, $try, $text, $lead, $step));
            } catch (Throwable $e) {
                report($e);
                $denied[] = $try['channel'].':error';

                continue;
            }
            if (in_array($result->status, [DispatchResult::QUEUED, DispatchResult::DUPLICATE], true)) {
                if ($result->status === DispatchResult::QUEUED) {
                    LeadActivity::log($lead, 'followup', 'ai', ['step' => $step, 'channel' => $try['channel'], 'mode' => $try['mode'], 'flow' => $state->flow->publicId()]);
                }

                return Outcome::next(record: 'sent', details: ['message_id' => $result->messageId, 'channel' => $try['channel'], 'mode' => $try['mode'], 'step' => $step]);
            }
            if ($result->status === DispatchResult::DEFERRED) {
                return Outcome::deferred($result->until ?? $state->now->addHour(), (string) $result->reason, ['draft' => encrypt($text), 'channel' => $try['channel']]);
            }
            $denied[] = $try['channel'].':'.$result->reason;
        }
        $this->task($state, $node, $lead, $step, implode(', ', $denied));

        return Outcome::next('failed', 'denied', ['reason' => implode(', ', $denied), 'step' => $step], fallback: true);
    }

    /**
     * Los caminos posibles en orden: el que dice LeadReach (texto en la ventana, plantilla
     * o correo) y, si ese era WhatsApp y el nodo permite correo, el correo de respaldo.
     *
     * @param  array<string, mixed>  $c
     * @param  list<'whatsapp'|'email'>  $channels
     * @return list<array{mode: string, channel: string, account: string|null, template: string|null}>
     */
    private function routes(RunState $state, array $c, array $channels): array
    {
        $reach = LeadReach::for($state->workspace->id, $state->contact, $channels, $state->now, marketing: true);
        $tries = [];
        if ($reach['mode'] === 'text' && $reach['conversation'] !== null) {
            $tries[] = ['mode' => 'text', 'channel' => (string) $reach['channel'], 'account' => $reach['conversation']->channel_account_id, 'template' => null];
        } elseif ($reach['mode'] === 'template') {
            $template = TemplateRef::resolve($state->workspace->id, 'whatsapp', $c['template_id'] ?? null, null) ?? $reach['template_id'];
            $tries[] = ['mode' => 'template', 'channel' => 'whatsapp', 'account' => $reach['conversation']?->channel_account_id, 'template' => $template];
        }
        $hasEmail = is_string($state->contact?->email_bidx) && $state->contact->email_bidx !== '';
        if ($reach['mode'] !== 'email' && in_array('email', $channels, true) && $hasEmail && ChannelAccount::defaultFor(Channel::Email) !== null) {
            $tries[] = ['mode' => 'email', 'channel' => 'email', 'account' => null, 'template' => null];
        } elseif ($reach['mode'] === 'email') {
            $tries[] = ['mode' => 'email', 'channel' => 'email', 'account' => null, 'template' => null];
        }

        return $tries;
    }

    /**
     * @param  array{id: string, type: string, config: array<string, mixed>}  $node
     * @param  array{mode: string, channel: string, account: string|null, template: string|null}  $try
     */
    private static function message(RunState $state, array $node, array $try, string $text, Lead $lead, int $step, ?string $to = null): OutboundMessage
    {
        $email = $try['mode'] === 'email';

        return new OutboundMessage(
            workspaceId: $state->workspace->id,
            contactId: $state->run->contact_id,
            channel: Channel::from($try['channel']),
            kind: MessageKind::Marketing,
            sourceType: 'flow',
            sourceId: $state->flow->id,
            templateId: $try['mode'] === 'template' ? $try['template'] : null,
            context: match ($try['mode']) {
                'template' => ['seguimiento' => ['mensaje' => $text, 'servicio' => $lead->service, 'paso' => $step], 'bandeja' => ['mensaje' => $text]],
                'email' => ['subject' => __('Sobre tu solicitud en :business', ['business' => $state->workspace->name]), 'text' => $text],
                default => ['text' => $text],
            },
            idempotencyKey: $state->idempotencyKey($node['id'], $email ? 'email' : 'whatsapp'),
            channelAccountId: $try['account'],
            flowId: $state->flow->id,
            to: $to,
            isTest: $to !== null,
        );
    }

    /**
     * En una prueba: el texto escrito por la IA va al correo de quien prueba.
     *
     * @param  array{id: string, type: string, config: array<string, mixed>}  $node
     */
    private function test(RunState $state, array $node, Lead $lead, ?string $text, int $step): Outcome
    {
        $to = $state->run->context['_test']['email'] ?? null;
        if (! is_string($to) || $to === '') {
            return Outcome::next('failed', 'failed', ['reason' => 'no_test_recipient'], fallback: true);
        }
        $text ??= $this->writer->write($state->workspace, $lead, 'followup', ['step' => $step, 'channel' => 'email'])['text'];
        $result = app(MessageDispatcher::class)->dispatch(self::message($state, $node, ['mode' => 'email', 'channel' => 'email', 'account' => null, 'template' => null], $text, $lead, $step, $to));

        return $result->sent() ? Outcome::next(record: 'sent', details: ['message_id' => $result->messageId, 'test' => true]) : Outcome::next('failed', 'failed', ['reason' => $result->reason], fallback: true);
    }

    /**
     * No salió solo: una tarea en «Hoy» para que el vendedor le escriba (con el mensaje sugerido allí).
     *
     * @param  array{id: string, type: string, config: array<string, mixed>}  $node
     */
    private function task(RunState $state, array $node, Lead $lead, int $step, string $why): void
    {
        $ref = $state->idempotencyKey($node['id'], 'task');
        if (LeadTask::query()->where('source_ref', $ref)->exists()) {
            return;
        }
        LeadTask::query()->create([
            'workspace_id' => $state->workspace->id, 'lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'owner_id' => $lead->owner_id,
            'kind' => 'task', 'title' => mb_substr(__('Seguimiento :step: no salió solo, escríbele tú', ['step' => $step]), 0, 160),
            'due_at' => $state->now, 'status' => 'open', 'source' => 'flow', 'source_ref' => $ref,
        ]);
        LeadActivity::log($lead, 'followup', 'system', ['step' => $step, 'denied' => mb_substr($why, 0, 200)]);
    }

    private static function decrypt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $text = decrypt($value);
        } catch (Throwable) {
            return null;
        }

        return is_string($text) && $text !== '' ? $text : null;
    }
}
