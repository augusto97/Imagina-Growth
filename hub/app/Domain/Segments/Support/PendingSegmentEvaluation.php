<?php

declare(strict_types=1);

namespace App\Domain\Segments\Support;

use App\Support\Tenancy\RedisKey;
use Illuminate\Support\Facades\Redis;

/**
 * Segmentos que esperan su recálculo en tiempo real para un contacto (R8 de la auditoría): cada
 * evento suma solo los segmentos que dependen de él, y el job (único por contacto, con espera)
 * los toma todos de una vez. Así un `page.viewed` reevalúa el segmento de visitas, no los ~20
 * del espacio de trabajo.
 *
 * Vive en el Redis de colas (sin desalojo), no en la caché: perder el conjunto sería no recalcular.
 */
final class PendingSegmentEvaluation
{
    private const TTL_SECONDS = 3600;

    private const MAX_TAKE = 1000;

    public static function key(string $workspaceId, string $contactId): string
    {
        return RedisKey::for($workspaceId, 'segeval:'.$contactId);
    }

    /** @param list<string> $segmentIds */
    public static function add(string $workspaceId, string $contactId, array $segmentIds): void
    {
        if ($segmentIds === []) {
            return;
        }
        $redis = Redis::connection();
        $key = self::key($workspaceId, $contactId);
        $redis->command('sadd', [$key, ...array_values(array_unique($segmentIds))]);
        $redis->command('expire', [$key, self::TTL_SECONDS]);
    }

    /**
     * Saca (de forma atómica) los segmentos pendientes del contacto.
     *
     * @return list<string>
     */
    public static function take(string $workspaceId, string $contactId): array
    {
        $popped = Redis::connection()->command('spop', [self::key($workspaceId, $contactId), self::MAX_TAKE]);

        return is_array($popped) ? array_values(array_map('strval', $popped)) : [];
    }
}
