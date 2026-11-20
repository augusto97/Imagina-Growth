<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Qué espacios de trabajo cambiaron su uso desde el último cálculo (R13), para que `igr:billing:usage --changed`
 * (cada 5 minutos) recalcule solo esos y no todos:
 *
 * - los que recibieron eventos de sus sitios después de su último cálculo (`sites.last_event_at`, que la ingesta
 *   ya mantiene: pedidos, contactos activos y eventos);
 * - los que enviaron mensajes o usaron IA, que se anotan en un conjunto de Redis (`touch`, solo IDs).
 *
 * El cálculo sigue siendo completo desde las tablas de origen (no se suman deltas), así que es exacto; el recorrido
 * de todos los espacios cada hora cubre lo demás (p. ej. la API).
 */
final class UsageActivity
{
    private const KEY = 'igr:billing:active';

    public static function touch(string $workspaceId): void
    {
        try {
            Redis::connection()->command('sadd', [self::KEY, $workspaceId]);
        } catch (Throwable) {
            // Sin Redis, el recorrido de cada hora lo recalcula igual.
        }
    }

    /**
     * Saca (de forma atómica) hasta $limit espacios anotados.
     *
     * @return list<string>
     */
    public static function pull(int $limit = 1000): array
    {
        try {
            $ids = Redis::connection()->command('spop', [self::KEY, max(1, $limit)]);
        } catch (Throwable) {
            return [];
        }

        return is_array($ids) ? array_values(array_map(fn ($id): string => (string) $id, $ids)) : [];
    }

    /**
     * Espacios con eventos de sus sitios después de su último cálculo (o que nunca se calcularon y ya tienen eventos).
     *
     * @return list<string>
     */
    public static function ingestedSinceLastComputation(): array
    {
        $last = DB::table('usage_counters')->selectRaw('workspace_id, MAX(computed_at) AS computed_at')->groupBy('workspace_id');
        $ids = DB::table('sites as s')->leftJoinSub($last, 'u', 'u.workspace_id', '=', 's.workspace_id')
            ->whereNotNull('s.last_event_at')
            ->where(fn ($q) => $q->whereNull('u.computed_at')->orWhereColumn('s.last_event_at', '>', 'u.computed_at'))
            ->distinct()->pluck('s.workspace_id')->all();

        return array_values(array_map(fn ($id): string => (string) $id, $ids));
    }
}
