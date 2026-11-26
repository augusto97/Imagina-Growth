<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use Illuminate\Support\Facades\Cache;

/**
 * La versión de la caché de las estrategias y del Inicio por espacio de trabajo: activar, pausar o cambiar el
 * asistente la sube y todo lo que se guardó con la versión anterior deja de servirse (vence solo). Lo que depende de
 * la ingesta (dinero, KPI) además lleva la versión de la capa semántica (MetricQueryRunner::remember).
 */
final class StrategyCache
{
    public static function version(string $workspaceId): int
    {
        return (int) Cache::get(self::key($workspaceId), 1);
    }

    public static function bump(string $workspaceId): void
    {
        Cache::forever(self::key($workspaceId), self::version($workspaceId) + 1);
    }

    private static function key(string $workspaceId): string
    {
        return 'ws:'.$workspaceId.':strategies:version';
    }
}
