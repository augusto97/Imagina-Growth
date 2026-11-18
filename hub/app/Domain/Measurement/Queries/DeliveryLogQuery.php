<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Queries;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;

/** Bitácora de entregas (ClickHouse conversion_deliveries) para el panel. */
final class DeliveryLogQuery
{
    /**
     * @param  array{destination_id?: string|null, status?: string|null, platform?: string|null, days?: int, page?: int, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, has_more: bool}
     */
    public function handle(Workspace $workspace, array $filters): array
    {
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $rows = Ch::forWorkspace($workspace)->select(
            'SELECT formatDateTime(delivered_at, \'%FT%T.%fZ\', \'UTC\') AS at, destination_id, platform, target, conversion, source_event,
                    toString(event_id) AS event_id, status, http_status, error, error_code, match_keys, attempts, final, test
             FROM conversion_deliveries
             WHERE workspace_id = {workspace_id:UInt32}
               AND delivered_at >= now() - toIntervalDay({days:UInt16})
               AND ({destination:String} = \'\' OR destination_id = {destination:String})
               AND ({status:String} = \'\' OR status = {status:String})
               AND ({platform:String} = \'\' OR platform = {platform:String})
             ORDER BY delivered_at DESC
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [
                'days' => min(90, max(1, (int) ($filters['days'] ?? 7))),
                'destination' => (string) ($filters['destination_id'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'platform' => (string) ($filters['platform'] ?? ''),
                'limit' => $perPage + 1,
                'offset' => ($page - 1) * $perPage,
            ],
        );
        $hasMore = count($rows) > $perPage;

        return [
            'data' => array_map(fn (array $r) => [
                'at' => $r['at'],
                'destination_id' => $r['destination_id'] !== '' ? 'dst_'.$r['destination_id'] : null,
                'platform' => $r['platform'],
                'target' => $r['target'],
                'conversion' => $r['conversion'],
                'source_event' => $r['source_event'],
                'event_id' => $r['event_id'],
                'status' => $r['status'],
                'http_status' => (int) $r['http_status'],
                'error' => $r['error'] !== '' ? $r['error'] : null,
                'error_code' => $r['error_code'] !== '' ? $r['error_code'] : null,
                'match_keys' => $r['match_keys'],
                'attempt' => (int) $r['attempts'],
                'final' => (bool) $r['final'],
                'test' => (bool) $r['test'],
            ], array_slice($rows, 0, $perPage)),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ];
    }
}
