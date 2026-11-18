<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Actions;

use App\Domain\Webhooks\Jobs\DeliverWebhook;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookSubscription;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Registra un intento de entrega y lo encola (o lo deja programado para `$at`). Idempotente:
 * el mismo evento no se entrega dos veces a la misma suscripción en el mismo intento.
 */
final class QueueWebhookDelivery
{
    public function handle(WebhookSubscription $sub, string $event, string $eventId, string $payload, string $kind = 'event', int $attempt = 1, ?CarbonInterface $at = null): ?WebhookDelivery
    {
        try {
            /** @var WebhookDelivery $delivery */
            $delivery = WebhookDelivery::withoutWorkspaceScope()->create([
                'workspace_id' => $sub->workspace_id, 'subscription_id' => $sub->id, 'event' => mb_substr($event, 0, 64),
                'event_id' => mb_substr($eventId, 0, 36), 'payload' => $payload, 'attempt' => min(255, $attempt), 'kind' => $kind,
                'next_attempt_at' => $at, 'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        if ($at === null) {
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }
}
