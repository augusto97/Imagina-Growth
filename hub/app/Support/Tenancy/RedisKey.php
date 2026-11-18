<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/** Las claves de Redis y la caché llevan el prefijo ws:{id}: (hub/CLAUDE.md §4). */
final class RedisKey
{
    public static function for(string $workspaceId, string $suffix): string
    {
        return 'ws:'.$workspaceId.':'.$suffix;
    }
}
