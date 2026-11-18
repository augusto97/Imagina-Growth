<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #5 Búsquedas frecuentes sin resultados (30 días, al menos 5 veces cada una),
 * con la propuesta de crear un sinónimo en Imagina Search o el producto. Las
 * búsquedas que parecen un correo o un teléfono se descartan.
 */
final class SearchNoResultsDetector implements Detector
{
    private const MIN_SEARCHES = 5;

    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'search_no_results';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(30);
        $ch = Ch::forWorkspace($workspace);
        $rows = $ch->select(<<<'SQL'
            SELECT lower(trim(toString(properties.query))) AS q, count() AS n FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'search.performed' AND occurred_at >= {since:DateTime64(3)}
              AND toString(properties.results_count) = '0' AND q != ''
            GROUP BY q HAVING n >= {min:UInt32} ORDER BY n DESC LIMIT 50
            SQL, ['since' => Data::ch($since), 'min' => self::MIN_SEARCHES]);
        $queries = [];
        foreach ($rows as $r) {
            $q = mb_substr((string) $r['q'], 0, 80);
            if (PiiScrubber::scrub($q) !== $q) {
                continue;
            }
            $queries[] = ['query' => $q, 'searches' => (int) $r['n']];
        }
        if ($queries === []) {
            return [];
        }
        $searches = array_sum(array_column($queries, 'searches'));
        $conv = $ch->select(<<<'SQL'
            SELECT count() AS sessions, countIf(purchased = 1) AS buyers FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {since:DateTime64(3)}
            SQL, ['since' => Data::ch($since)])[0] ?? ['sessions' => 0, 'buyers' => 0];
        $rate = (int) $conv['sessions'] > 0 ? (int) $conv['buyers'] / (int) $conv['sessions'] : 0.0;
        $aov = Data::aov($workspace, $since);
        $capture = $this->assumptions->value('search_capture_rate');
        $formula = Impact::of(
            new Term(__('búsquedas sin resultados'), $searches, 'count'),
            new Term(__('de conversión del sitio'), round($rate, 4), 'rate'),
            new Term(__('que compraría si lo encuentra'), $capture, 'rate', 'search_capture_rate'),
            new Term(__('de ticket'), $aov, 'money'),
        );

        return [new InsightCandidate(
            fingerprint: 'queries',
            severity: $searches >= 100 ? 'warning' : 'opportunity',
            title: __('«:q» y otras :n búsquedas no encuentran nada en tu tienda', ['q' => $queries[0]['query'], 'n' => count($queries) - 1]),
            facts: ['queries' => array_slice($queries, 0, 15), 'searches' => $searches, 'site_conversion' => round($rate, 4), 'aov' => round($aov, 2), 'window_days' => 30],
            assumptions: [__('Crea el sinónimo en Imagina Search o el producto que buscan; las búsquedas sin resultado suelen ser ventas perdidas.')],
            action: ['label' => __('Revisa las búsquedas sin resultados'), 'to' => 'analitica/busquedas'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
        )];
    }
}
