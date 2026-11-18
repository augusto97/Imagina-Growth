<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

use App\Domain\Webhooks\Models\WebhookSubscription;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Las suscripciones vivas de un espacio de trabajo en caché (5 min), para que la ingesta no
 * consulte la base por cada evento. Se olvida al crear, cambiar o pausar una suscripción.
 */
final class SubscriptionIndex
{
    /** @return list<array{id: string, events: list<string>}> */
    public static function for(string $workspaceId): array
    {
        /** @var list<array{id: string, events: list<string>}> */
        return Cache::remember(self::key($workspaceId), 300, fn () => WebhookSubscription::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)->where('status', '!=', WebhookSubscription::PAUSED)
            ->get(['id', 'events'])
            ->map(fn (WebhookSubscription $s) => ['id' => $s->id, 'events' => array_values(array_map('strval', (array) $s->events))])
            ->values()->all());
    }

    /** @return list<string> IDs de las suscripciones que quieren el evento */
    public static function matching(string $workspaceId, string $event): array
    {
        $ids = [];
        foreach (self::for($workspaceId) as $sub) {
            foreach ($sub['events'] as $pattern) {
                if (WebhookSubscription::matches($pattern, $event)) {
                    $ids[] = $sub['id'];

                    break;
                }
            }
        }

        return $ids;
    }

    public static function forget(string $workspaceId): void
    {
        Cache::forget(self::key($workspaceId));
    }

    private static function key(string $workspaceId): string
    {
        return RedisKey::for($workspaceId, 'webhooks:index');
    }
}
