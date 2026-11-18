<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Push;

use App\Domain\Inbox\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push real (`minishlink/web-push`) con las llaves VAPID del hub
 * (`config/services.php` → `webpush`: `IGR_PUSH_VAPID_PUBLIC_KEY`,
 * `IGR_PUSH_VAPID_PRIVATE_KEY`, generadas con `igr:push:vapid`). El cuerpo solo lleva el título y el enlace (sin texto del
 * mensaje ni datos del contacto). Las suscripciones vencidas (404/410) se borran.
 */
final class WebPushNotifier implements PushNotifier
{
    public function __construct(private readonly ?WebPush $client = null) {}

    public function notify(string $workspaceId, string $userId, string $title, string $url): int
    {
        $subs = PushSubscription::withoutWorkspaceScope()->where('workspace_id', $workspaceId)->where('user_id', $userId)->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        try {
            $push = $this->client ?? self::client();
        } catch (Throwable $e) {
            Log::warning('igr.push.misconfigured', ['error' => class_basename($e)]);

            return 0;
        }
        $payload = (string) json_encode(['title' => mb_substr($title, 0, 80), 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byEndpoint = [];
        foreach ($subs as $sub) {
            $keys = (array) $sub->keys;
            $byEndpoint[$sub->endpoint] = $sub;
            $push->queueNotification(Subscription::create([
                'endpoint' => $sub->endpoint,
                'publicKey' => (string) ($keys['p256dh'] ?? ''),
                'authToken' => (string) ($keys['auth'] ?? ''),
                'contentEncoding' => 'aes128gcm', // RFC 8291: la que usan los navegadores actuales
            ]), $payload, ['TTL' => 3600, 'urgency' => 'high']);
        }
        $sent = 0;
        foreach ($push->flush() as $report) {
            $sub = $byEndpoint[$report->getEndpoint()] ?? null;
            if ($report->isSuccess()) {
                $sent++;
                $sub?->forceFill(['last_used_at' => now()])->save();
            } elseif ($report->isSubscriptionExpired()) {
                $sub?->delete();
            }
        }

        return $sent;
    }

    public static function configured(): bool
    {
        return (string) config('services.webpush.public_key') !== '' && (string) config('services.webpush.private_key') !== '';
    }

    private static function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => (string) config('services.webpush.subject', 'mailto:soporte@imagina.cloud'),
            'publicKey' => (string) config('services.webpush.public_key'),
            'privateKey' => (string) config('services.webpush.private_key'),
        ]], ['TTL' => 3600], logger: app('log')->channel());
    }
}
