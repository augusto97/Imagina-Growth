<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Intelligence\Ai\Features\Reactivation\ReactivationWriter;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\ConversationWindow;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «Reactivar con IA» (M09, auditoría §5.1 función 7, «despierta la base dormida»): la IA
 * escribe un mensaje para retomar según la última conversación del contacto y su historia
 * de compras (ReactivationWriter, con la validación de cifras y datos), y sale por el
 * despachador como **marketing**: consentimiento de ese canal, horario legal del país del
 * contacto y frecuencia (SendPolicy; cumplimiento.md §4).
 *
 * - WhatsApp con la ventana de 24 h abierta: texto libre en la conversación.
 * - WhatsApp con la ventana cerrada: solo la plantilla de **marketing** aprobada del nodo
 *   (o la de `library_key` wa_reactivation), con el texto como valor de su variable
 *   `reactivacion.mensaje` (lo demás es el texto que aprobó Meta). Meta solo admite
 *   plantillas fuera de la ventana y la personalización va en los parámetros:
 *   https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/overview
 *   https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/marketing-templates/custom-marketing-templates/
 * - Correo como respaldo, si el nodo lo permite.
 *
 * Antes de llamar a la IA revisa la política sin reservar cupo: a quien no se le puede
 * escribir no se le gasta una llamada, y fuera de horario el paso se aplaza sin texto
 * (se escribe al salir, con la conversación de ese momento). Un aplazamiento del
 * despachador vuelve con el mismo texto, cifrado en el paso, y la misma clave. Si no sale
 * por ningún canal, sigue por «falló». El grupo de control no recibe nada; en una prueba
 * el texto va al correo de quien prueba.
 */
final class AiReactivateHandler implements NodeHandler
{
    public const TEMPLATE_KEY = 'wa_reactivation';

    public function __construct(private readonly ReactivationWriter $writer, private readonly SendPolicy $policy) {}

    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        $contact = $state->contact;
        if ($contact === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_contact'], fallback: true);
        }
        $c = $node['config'];
        /** @var list<'whatsapp'|'email'> $channels */
        $channels = array_values(array_intersect(['whatsapp', 'email'], (array) ($c['channels'] ?? ['whatsapp', 'email'])));
        $offer = is_string($c['offer'] ?? null) && trim($c['offer']) !== '' ? trim($c['offer']) : null;
        $lookback = is_int($c['lookback_days'] ?? null) ? $c['lookback_days'] : 365;
        $previous = $state->stepDetails();
        $draft = self::decrypt($previous['draft'] ?? null);

        if ($state->isTest()) {
            return $this->test($state, $node, $draft, $offer, $lookback);
        }

        $routes = $this->routes($state, $c, $channels);
        if ($routes === []) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_channel'], fallback: true);
        }
        // Sin reservar: a quien no se le puede escribir no se le gasta una llamada a la IA.
        $open = [];
        $until = null;
        $deferReason = null;
        $denied = [];
        foreach ($routes as $route) {
            $decision = $this->policy->check(new PolicyRequest($state->workspace, $contact, Channel::from($route['channel']), MessageKind::Marketing, 'flow',
                $route['account'] !== null ? ChannelAccount::query()->find($route['account']) : null, $state->now));
            if ($decision->allowed()) {
                $open[] = $route;
            } elseif ($decision->deferred()) {
                if ($until === null || ($decision->until !== null && $decision->until->lessThan($until))) {
                    [$until, $deferReason] = [$decision->until, (string) $decision->reason];
                }
            } else {
                $denied[] = $route['channel'].':'.$decision->reason;
            }
        }
        if ($open === []) {
            return $until !== null
                ? Outcome::deferred($until, (string) $deferReason, $draft !== null ? ['draft' => encrypt($draft)] : [])
                : Outcome::next('failed', 'denied', ['reason' => implode(', ', $denied)], fallback: true);
        }

        $written = null;
        foreach ($open as $route) {
            // El texto se escribe una vez para el canal que sale; si ese no sale, el correo usa el mismo.
            if ($draft === null) {
                $written = $this->writer->write($state->workspace, $contact, $route['mode'], $offer, $lookback);
                $draft = $written['text'];
            }
            try {
                $result = app(MessageDispatcher::class)->dispatch($this->message($state, $node, $route, $draft));
            } catch (Throwable $e) {
                report($e);
                $denied[] = $route['channel'].':error';

                continue;
            }
            if (in_array($result->status, [DispatchResult::QUEUED, DispatchResult::DUPLICATE], true)) {
                return Outcome::next(record: 'sent', details: array_filter(['message_id' => $result->messageId, 'channel' => $route['channel'], 'mode' => $route['mode'],
                    'source' => $written['source'] ?? null, 'summary' => isset($written['summary']) ? encrypt($written['summary']) : null], fn ($v) => $v !== null));
            }
            if ($result->status === DispatchResult::DEFERRED) {
                return Outcome::deferred($result->until ?? $state->now->addHour(), (string) $result->reason, ['draft' => encrypt($draft), 'channel' => $route['channel']]);
            }
            $denied[] = $route['channel'].':'.$result->reason;
        }

        return Outcome::next('failed', 'denied', ['reason' => implode(', ', $denied)], fallback: true);
    }

    /**
     * Los caminos en el orden del nodo: WhatsApp (texto con la ventana abierta o la plantilla
     * de marketing con la ventana cerrada) y correo.
     *
     * @param  array<string, mixed>  $c
     * @param  list<'whatsapp'|'email'>  $channels
     * @return list<array{mode: 'text'|'template'|'email', channel: string, account: string|null, template: string|null}>
     */
    private function routes(RunState $state, array $c, array $channels): array
    {
        $contact = $state->contact;
        $out = [];
        foreach ($channels as $channel) {
            if ($channel === 'whatsapp' && is_string($contact?->phone_bidx) && $contact->phone_bidx !== '') {
                $open = $this->openConversation($state);
                if ($open !== null) {
                    $out[] = ['mode' => 'text', 'channel' => 'whatsapp', 'account' => $open->channel_account_id, 'template' => null];

                    continue;
                }
                $template = self::template($state->workspace->id, $c['template_id'] ?? null);
                if ($template !== null && ChannelAccount::defaultFor(Channel::WhatsApp) !== null) {
                    $out[] = ['mode' => 'template', 'channel' => 'whatsapp', 'account' => null, 'template' => $template];
                }
            } elseif ($channel === 'email' && is_string($contact?->email_bidx) && $contact->email_bidx !== '' && ChannelAccount::defaultFor(Channel::Email) !== null) {
                $out[] = ['mode' => 'email', 'channel' => 'email', 'account' => null, 'template' => null];
            }
        }

        return $out;
    }

    /** La conversación de WhatsApp del contacto con la ventana de 24 h abierta, si hay. */
    private function openConversation(RunState $state): ?Conversation
    {
        $accounts = ChannelAccount::query()->where('channel', Channel::WhatsApp->value)->where('status', 'active')->pluck('id')->all();
        $conversation = Conversation::query()->where('contact_id', (string) $state->run->contact_id)->whereIn('channel_account_id', $accounts)
            ->orderByDesc('last_inbound_at')->first();

        return $conversation !== null && ConversationWindow::state(Channel::WhatsApp, $conversation->window_expires_at, $conversation->last_inbound_at, $state->now) === ConversationWindow::OPEN
            ? $conversation : null;
    }

    /** La plantilla de WhatsApp del nodo o la de la biblioteca, solo si es de marketing (no se disfraza de utilidad). */
    public static function template(string $workspaceId, mixed $templateId): ?string
    {
        $id = TemplateRef::resolve($workspaceId, 'whatsapp', $templateId, null) ?? TemplateRef::resolve($workspaceId, 'whatsapp', null, self::TEMPLATE_KEY);
        if ($id === null) {
            return null;
        }

        return DB::table('templates')->where('workspace_id', $workspaceId)->where('id', $id)->value('category') === 'marketing' ? $id : null;
    }

    /**
     * @param  array{id: string, type: string, config: array<string, mixed>}  $node
     * @param  array{mode: string, channel: string, account: string|null, template: string|null}  $route
     */
    private function message(RunState $state, array $node, array $route, string $text, ?string $to = null): OutboundMessage
    {
        return new OutboundMessage(
            workspaceId: $state->workspace->id,
            contactId: $state->run->contact_id,
            channel: Channel::from($route['channel']),
            kind: MessageKind::Marketing,
            sourceType: 'flow',
            sourceId: $state->flow->id,
            templateId: $route['mode'] === 'template' ? $route['template'] : null,
            context: match ($route['mode']) {
                'template' => ['reactivacion' => ['mensaje' => $text]],
                'email' => ['subject' => __('Hola de nuevo desde :business', ['business' => $state->workspace->name]), 'text' => $text],
                default => ['text' => $text],
            },
            idempotencyKey: $state->idempotencyKey($node['id'], $route['channel']),
            channelAccountId: $route['account'],
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
    private function test(RunState $state, array $node, ?string $draft, ?string $offer, int $lookback): Outcome
    {
        $to = $state->run->context['_test']['email'] ?? null;
        if (! is_string($to) || $to === '' || $state->contact === null) {
            return Outcome::next('failed', 'failed', ['reason' => 'no_test_recipient'], fallback: true);
        }
        $draft ??= $this->writer->write($state->workspace, $state->contact, 'email', $offer, $lookback)['text'];
        $result = app(MessageDispatcher::class)->dispatch($this->message($state, $node, ['mode' => 'email', 'channel' => 'email', 'account' => null, 'template' => null], $draft, $to));

        return $result->sent() ? Outcome::next(record: 'sent', details: ['message_id' => $result->messageId, 'test' => true]) : Outcome::next('failed', 'failed', ['reason' => $result->reason], fallback: true);
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
