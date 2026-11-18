<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;

/** 6. Búsquedas: términos principales, búsquedas sin resultados y conversión por término. */
final class SearchesDashboard extends Dashboard
{
    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        [$fsql, $params] = $filters->eventsSql();
        $rows = Ch::forWorkspace($workspace)->select(
            "SELECT q.query AS query, count() AS searches, uniqExact(q.session_id) AS sessions,
                    countIf(q.results = 0) AS zero_results, avg(q.results) AS avg_results,
                    uniqExactIf(q.session_id, s.purchased = 1) AS converted
             FROM (
                SELECT lowerUTF8(trimBoth(toString(properties.query))) AS query, session_id,
                       toInt64OrNull(toString(properties.results_count)) AS results
                FROM events
                WHERE workspace_id = {workspace_id:UInt32} AND event = 'search.performed'
                  AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {to:DateTime64(3)}{$fsql}
             ) AS q
             LEFT JOIN (
                SELECT session_id, max(purchased) AS purchased FROM sessions FINAL
                WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from:DateTime64(3)} - INTERVAL 1 DAY AND started_at < {to:DateTime64(3)}
                GROUP BY session_id
             ) AS s ON s.session_id = q.session_id
             WHERE q.query != ''
             GROUP BY q.query ORDER BY searches DESC LIMIT 200",
            $params + self::chRange($period),
        );
        $terms = array_map(fn ($r) => [
            'query' => (string) $r['query'], 'searches' => (int) $r['searches'], 'sessions' => (int) $r['sessions'],
            'zero_results' => (int) $r['zero_results'], 'avg_results' => $r['avg_results'] === null ? null : round((float) $r['avg_results'], 1),
            'conversion' => self::ratio((int) $r['converted'], (int) $r['sessions']),
        ], $rows);
        $zero = array_values(array_filter($terms, fn ($t) => $t['zero_results'] > 0 && $t['zero_results'] >= $t['searches'] / 2));
        $total = array_sum(array_column($terms, 'searches'));

        return [
            'total_searches' => $total,
            'zero_result_share' => self::ratio(array_sum(array_column($terms, 'zero_results')), $total),
            'terms' => array_slice($terms, 0, 50),
            'zero_results' => array_slice($zero, 0, 20),
        ];
    }
}
