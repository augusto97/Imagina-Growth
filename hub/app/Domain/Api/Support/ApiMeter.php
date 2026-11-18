<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Peticiones a la API pública por espacio de trabajo y mes (M21 las cuenta en
 * `usage_counters.api_requests`). Un contador en la caché (Redis en producción) que se
 * suma en cada petición autenticada; `count()` lo lee.
 */
final class ApiMeter
{
    public static function hit(string $workspaceId): void
    {
        $key = self::key($workspaceId, now()->format('Y-m'));
        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->addDays(40));
        }
        Cache::increment($key);
    }

    /** @param string|null $period YYYY-MM (por defecto el mes en curso) */
    public static function count(string $workspaceId, ?string $period = null): int
    {
        return (int) Cache::get(self::key($workspaceId, $period ?? now()->format('Y-m')), 0);
    }

    private static function key(string $workspaceId, string $period): string
    {
        return RedisKey::for($workspaceId, 'api:requests:'.$period);
    }
}
