<?php

declare(strict_types=1);

namespace App\Domain\Segments\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Tendencia del tamaño de cada grupo de clientes (segmento) en los últimos días, con las filas diarias que escribe
 * RecomputeSegment::refreshStats (`segment_daily_counts`). Una sola consulta para todos los segmentos del espacio de
 * trabajo. Con menos de dos días medidos no hay tendencia (`change` = null): nunca se inventa un punto de partida.
 */
final class SegmentTrends
{
    public const DAYS = 30;

    /**
     * @param  list<string>|null  $segmentIds  ids internos; null = todos los del espacio de trabajo
     * @return array<string, array{points: list<int>, change: int|null, days: int}>
     */
    public static function forWorkspace(string $workspaceId, ?array $segmentIds = null, int $days = self::DAYS): array
    {
        $rows = DB::table('segment_daily_counts')
            ->where('workspace_id', $workspaceId)
            ->where('day', '>=', now()->subDays($days)->toDateString())
            ->when($segmentIds !== null, fn ($q) => $q->whereIn('segment_id', $segmentIds))
            ->orderBy('day')
            ->get(['segment_id', 'day', 'member_count']);

        $out = [];
        foreach ($rows->groupBy('segment_id') as $segmentId => $points) {
            $values = array_values(array_map(fn ($r) => (int) $r->member_count, $points->all()));
            $out[(string) $segmentId] = [
                'points' => $values,
                'change' => count($values) >= 2 ? $values[count($values) - 1] - $values[0] : null,
                'days' => $days,
            ];
        }

        return $out;
    }
}
