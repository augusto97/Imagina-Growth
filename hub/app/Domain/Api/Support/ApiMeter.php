<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

use App\Domain\Billing\Usage\UsageCalculator;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Cache;

/**
 * Peticiones a la API pública y al servidor MCP por espacio de trabajo y mes (M21 las
 * guarda en `usage_counters.api_requests`). Un contador en la caché (Redis en producción)
 * que se suma en cada petición autenticada; `count()` lo lee. El mes es el del espacio de
 * trabajo en su zona horaria, igual que el resto del uso (UsageCalculator).
 */
final class ApiMeter
{
    public static function hit(Workspace $workspace): void
    {
        $key = self::key((string) $workspace->id, UsageCalculator::currentPeriod($workspace));
        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->addDays(40));
        }
        Cache::increment($key);
    }

    /** @param string|null $period YYYY-MM (por defecto el mes en curso del espacio de trabajo) */
    public static function count(Workspace $workspace, ?string $period = null): int
    {
        return (int) Cache::get(self::key((string) $workspace->id, $period ?? UsageCalculator::currentPeriod($workspace)), 0);
    }

    private static function key(string $workspaceId, string $period): string
    {
        return RedisKey::for($workspaceId, 'api:requests:'.$period);
    }
}
