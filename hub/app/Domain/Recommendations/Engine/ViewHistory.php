<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Engine;

use App\Analytics\Ch;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Productos que vio una persona (`product.viewed` en ClickHouse), por sus
 * navegadores o su contacto, del más reciente al más viejo. Si ClickHouse no
 * responde, devuelve una lista vacía: la recomendación sigue con lo demás.
 */
final class ViewHistory
{
    /**
     * @param  list<string>  $anonymousIds
     * @return list<array{product_id: string, at: CarbonImmutable}>
     */
    public function recent(Workspace $workspace, int $siteChId, array $anonymousIds, ?string $contactId, int $days = 90, int $limit = 30): array
    {
        if ($anonymousIds === [] && $contactId === null) {
            return [];
        }
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT product_id, formatDateTime(max(occurred_at), '%Y-%m-%dT%H:%i:%SZ', 'UTC') AS last_at
                 FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND site_id = {site:UInt32} AND event = 'product.viewed'
                   AND occurred_at >= {from:DateTime64(3)} AND product_id != ''
                   AND (has({anon:Array(String)}, anonymous_id) OR contact_id = {contact:String})
                 GROUP BY product_id
                 ORDER BY max(occurred_at) DESC
                 LIMIT {limit:UInt32}",
                [
                    'site' => $siteChId,
                    'from' => CarbonImmutable::now()->subDays($days)->utc()->format('Y-m-d H:i:s.v'),
                    'anon' => ExportContactData::chArray($anonymousIds),
                    'contact' => $contactId ?? '-',
                    'limit' => $limit,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('igr.reco.history_unavailable', ['workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return [];
        }

        return array_map(fn (array $r) => ['product_id' => (string) $r['product_id'], 'at' => CarbonImmutable::parse((string) $r['last_at'])], $rows);
    }
}
