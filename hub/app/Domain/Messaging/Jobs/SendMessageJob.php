<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Jobs;

use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Channels\Data\OutgoingMessage;
use App\Domain\Messaging\Channels\DriverRegistry;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Support\MessageEvents;
use App\Domain\Messaging\Support\SendCaps;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Envía un mensaje ya aprobado por SendPolicy (cola `messages:{canal}`).
 *
 * 1. Revisa otra vez la ventana legal justo antes del proveedor: si la cola se
 *    demoró y ya cerró, el mensaje queda `deferred` hasta la siguiente apertura
 *    (lo vuelve a encolar `igr:messages:requeue`, cada minuto).
 * 2. Topes de la cuenta: calentamiento del dominio y topes fijos de Zender.
 * 3. Límite de velocidad por cuenta (Redis).
 * 4. Pasa a `sending` de forma atómica (un reintento nunca envía dos veces) y
 *    llama al driver. Un error transitorio vuelve a `queued` con espera; uno
 *    definitivo, 5 transitorios o una excepción del driver lo deja `failed` y
 *    devuelve el cupo de frecuencia. Si el proceso muere en medio, el mensaje
 *    queda en `sending` y lo resuelve `igr:messages:stuck` (ResolveStuckMessages).
 */
final class SendMessageJob implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_PROVIDER_ATTEMPTS = 5;

    /** @var list<int> espera tras un error transitorio del proveedor */
    public const BACKOFF = [30, 120, 600, 1800];

    public int $timeout = 60;

    /** Intentos del worker; explícitos para no depender del supervisor de Horizon (perfil pequeño). */
    public int $tries = 5;

    public function __construct(public string $workspaceIdValue, public string $messageId) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    /** Los reintentos por límite de velocidad no cuentan como fallos: se reintenta durante un día. */
    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDay();
    }

    public function handle(SendPolicy $policy, DriverRegistry $drivers, MessageEvents $events): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message === null || $message->status !== 'queued') {
            return;
        }
        $workspace = Workspace::query()->findOrFail($message->workspace_id);
        $account = $message->channel_account_id !== null ? ChannelAccount::query()->find($message->channel_account_id) : null;
        $contact = $message->contact_id !== null ? Contact::query()->find($message->contact_id) : null;
        $payload = $message->payload ?? [];
        $request = new PolicyRequest($workspace, $contact, $message->channel, $message->kind, $message->source_type, $account, null, (bool) ($payload['test'] ?? false), $contact === null);

        // 1. Ventana legal otra vez (una cola lenta no puede sacar marketing a las 19:01).
        $window = $policy->windowAllows($request);
        if (! $window->allowed()) {
            $message->forceFill(['status' => 'deferred', 'deferred_until' => $window->until, 'policy_reason' => $window->reason])->save();

            return;
        }
        if ($account === null || ! $account->isActive()) {
            $this->fail($message, $policy, $request, $events, __('La cuenta del canal ya no está activa.'), 'channel_unavailable');

            return;
        }

        // 2. Topes de la cuenta (calentamiento, Zender).
        $capUntil = SendCaps::reserve($account);
        if ($capUntil !== null) {
            $message->forceFill(['status' => 'deferred', 'deferred_until' => $capUntil, 'policy_reason' => 'account_cap'])->save();

            return;
        }

        // 3. Velocidad por cuenta.
        $driver = $drivers->for($account->provider);
        $rate = max(1, $driver->capabilities()['rate_per_second']);
        $allowed = Redis::connection()->throttle('ws:'.$workspace->id.':send:'.$account->id)->allow($rate)->every(1)->block(0)
            ->then(fn () => true, fn () => false);
        if ($allowed !== true) {
            SendCaps::release($account);
            $this->release(1);

            return;
        }

        // 4. Envío, una sola vez.
        if (Message::query()->whereKey($message->id)->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()]) !== 1) {
            SendCaps::release($account);

            return;
        }
        $message->status = 'sending';
        $message->syncOriginalAttribute('status');
        try {
            $result = $driver->send($account, new OutgoingMessage(
                $message->publicId(),
                (string) ($payload['to'] ?? ''),
                $message->kind->isCommercial() && ! ($payload['test'] ?? false),
                isset($payload['subject']) ? (string) $payload['subject'] : null,
                isset($payload['html']) ? (string) $payload['html'] : null,
                isset($payload['text']) ? (string) $payload['text'] : null,
                (array) ($payload['headers'] ?? []),
                isset($payload['wa']['name']) ? (string) $payload['wa']['name'] : null,
                isset($payload['wa']['language']) ? (string) $payload['wa']['language'] : null,
                array_values((array) ($payload['wa']['components'] ?? [])),
                isset($payload['messaging_tag']) ? (string) $payload['messaging_tag'] : null,
            ));
        } catch (Throwable $e) {
            // Una excepción del driver dejaba el mensaje en `sending` para siempre (F9): queda fallido con el motivo.
            report($e);
            SendCaps::release($account);
            $this->fail($message, $policy, $request, $events, __('Error inesperado al enviar (:type).', ['type' => class_basename($e)]), 'driver_error');

            return;
        }

        if ($result->ok) {
            $message->forceFill(['status' => 'sent', 'provider_message_id' => $result->providerMessageId, 'sent_at' => now()])->save();
            $events->emit($workspace, $message, 'message.sent');

            return;
        }
        SendCaps::release($account);
        $attempts = (int) ($payload['attempts'] ?? 0) + 1;
        if ($result->retryable && $attempts < self::MAX_PROVIDER_ATTEMPTS) {
            $payload['attempts'] = $attempts;
            $message->forceFill(['status' => 'queued', 'payload' => $payload, 'failed_reason' => $result->error, 'error_code' => $result->errorCode])->save();
            $this->release(self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)]);

            return;
        }
        $this->fail($message, $policy, $request, $events, (string) $result->error, $result->errorCode);
    }

    private function fail(Message $message, SendPolicy $policy, PolicyRequest $request, MessageEvents $events, string $reason, ?string $code): void
    {
        $message->forceFill(['status' => 'failed', 'failed_reason' => mb_substr($reason, 0, 500), 'error_code' => $code])->save();
        $policy->release($request, CarbonImmutable::instance($message->queued_at ?? now()));
        $workspace = Workspace::query()->findOrFail($message->workspace_id);
        $events->emit($workspace, $message, 'message.failed', ['reason' => mb_substr($reason, 0, 500), 'error_code' => $code]);
    }
}
