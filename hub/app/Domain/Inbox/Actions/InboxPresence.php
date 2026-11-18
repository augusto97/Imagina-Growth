<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Quién está atendiendo la bandeja: la SPA avisa cada minuto mientras la persona
 * está «disponible» y la marca vence a los 2 minutos. La asignación automática
 * solo reparte entre disponibles.
 */
final class InboxPresence
{
    private const TTL = 120;

    public function heartbeat(string $workspaceId, string $userId, bool $available): void
    {
        $key = RedisKey::for($workspaceId, 'inbox:online:'.$userId);
        $available ? Cache::put($key, 1, self::TTL) : Cache::forget($key);
    }

    public function isOnline(string $workspaceId, string $userId): bool
    {
        return Cache::has(RedisKey::for($workspaceId, 'inbox:online:'.$userId));
    }
}
