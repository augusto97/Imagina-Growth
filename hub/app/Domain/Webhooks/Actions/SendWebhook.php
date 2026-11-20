<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Actions;

use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Domain\Webhooks\Support\SubscriptionIndex;
use App\Support\Hmac\Signature;
use App\Support\Net\SafeHttp;
use App\Support\Net\UnsafeUrl;
use App\Support\Pii\Pii;
use Throwable;

/**
 * Hace el POST de un intento (docs/api-publica.md §9.3): cabeceras `X-IGR-Signature`
 * (`t=…,v1=…`, el mismo esquema del connector), `X-IGR-Event`, `X-IGR-Delivery` y
 * `X-IGR-Attempt`. Éxito = 2xx en menos de 10 s. Si falla, programa el siguiente intento
 * (1 min, 5 min, 30 min, 2 h, 12 h) y, tras N fallos seguidos, pausa la suscripción y avisa
 * a los dueños por correo.
 */
final class SendWebhook
{
    /** Segundos de espera antes de cada reintento. */
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    public function __construct(private readonly QueueWebhookDelivery $queue, private readonly NotifyWebhookPaused $notify) {}

    public function handle(int $deliveryId): ?WebhookDelivery
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::withoutWorkspaceScope()->find($deliveryId);
        if ($delivery === null || $delivery->status_code !== null || $delivery->error !== null) {
            return $delivery;
        }
        /** @var WebhookSubscription|null $sub */
        $sub = WebhookSubscription::withoutWorkspaceScope()->find($delivery->subscription_id);
        if ($sub === null) {
            return null;
        }
        if ($sub->status === WebhookSubscription::PAUSED && $delivery->kind !== 'ping' && $delivery->kind !== 'replay') {
            $delivery->forceFill(['error' => 'paused', 'next_attempt_at' => null])->save();

            return $delivery;
        }

        $body = (string) $delivery->payload;
        $started = microtime(true);
        $status = null;
        $error = null;
        try {
            // Resuelve el DNS ahora y conecta a la IP revisada: nada de redes internas ni DNS rebinding (S5).
            $request = SafeHttp::for($sub->url);
        } catch (UnsafeUrl $e) {
            $request = null;
            $error = $e->reason === 'dns_unresolved' ? 'dns_unresolved' : 'url_not_public';
        }
        if ($request !== null) {
            try {
                $response = $request->timeout(10)->connectTimeout(5)
                    ->withHeaders([
                        'User-Agent' => 'ImaginaGrowth-Webhooks/1.0',
                        'X-IGR-Signature' => Signature::sign($sub->secret, $body),
                        'X-IGR-Event' => $delivery->event,
                        'X-IGR-Delivery' => 'whd_'.$delivery->id,
                        'X-IGR-Attempt' => (string) $delivery->attempt,
                    ])
                    ->withBody($body, 'application/json')
                    ->post($sub->url);
                $status = $response->status();
                if (! $response->successful()) {
                    $error = 'HTTP '.$status;
                }
            } catch (Throwable $e) {
                $error = mb_substr(Pii::scrub(class_basename($e).': '.$e->getMessage()), 0, 255);
            }
        }
        $delivery->forceFill([
            'status_code' => $status, 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => $error, 'next_attempt_at' => null,
        ])->save();

        if ($delivery->kind === 'ping') {
            return $delivery;
        }
        if ($error === null) {
            if ($sub->consecutive_failures > 0 || $sub->status !== WebhookSubscription::ACTIVE) {
                $wasFailing = $sub->status === WebhookSubscription::FAILING;
                $sub->forceFill(['consecutive_failures' => 0, 'status' => $sub->status === WebhookSubscription::PAUSED ? WebhookSubscription::PAUSED : WebhookSubscription::ACTIVE])->save();
                if ($wasFailing) {
                    SubscriptionIndex::forget($sub->workspace_id);
                }
            }
            $sub->forceFill(['last_success_at' => now()])->save();

            return $delivery;
        }

        $sub->consecutive_failures++;
        $pauseAfter = max(1, (int) config('igr.webhooks.pause_after_failures', 15));
        if ($sub->consecutive_failures >= $pauseAfter && $sub->status !== WebhookSubscription::PAUSED) {
            $sub->status = WebhookSubscription::PAUSED;
            $sub->save();
            SubscriptionIndex::forget($sub->workspace_id);
            $this->notify->handle($sub, $error);

            return $delivery;
        }
        if ($sub->status === WebhookSubscription::ACTIVE) {
            $sub->status = WebhookSubscription::FAILING;
        }
        $sub->save();

        // Reintentos automáticos solo para entregas de eventos (no para reenvíos manuales ni pruebas).
        $retryIndex = $delivery->attempt - 1;
        if (in_array($delivery->kind, ['event', 'retry'], true) && isset(self::BACKOFF[$retryIndex]) && $sub->status !== WebhookSubscription::PAUSED) {
            $this->queue->handle($sub, $delivery->event, $delivery->event_id, $body, 'retry', $delivery->attempt + 1, now()->addSeconds(self::BACKOFF[$retryIndex]));
        }

        return $delivery;
    }
}
