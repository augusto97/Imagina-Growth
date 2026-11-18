<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #13 Ciudad con potencial: mucho tráfico (al menos 200 sesiones y el 5 % del
 * total en 30 días) y la mitad o menos de la conversión del sitio. ¿Envío caro o
 * lento? Hasta 3 ciudades.
 */
final class CityPotentialDetector implements Detector
{
    public function __construct(private readonly Assumptions $assumptions) {}

    public function key(): string
    {
        return 'city_potential';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(30);
        $rows = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT city, count() AS sessions, countIf(purchased = 1) AS buyers FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {since:DateTime64(3)}
            GROUP BY city
            SQL, ['since' => Data::ch($since)]);
        $total = array_sum(array_map(fn ($r) => (int) $r['sessions'], $rows));
        $buyers = array_sum(array_map(fn ($r) => (int) $r['buyers'], $rows));
        if ($total < 1000 || $buyers < 10) {
            return [];
        }
        $siteRate = $buyers / $total;
        $aov = Data::aov($workspace, $since);
        $share = $this->assumptions->value('city_fix_share');
        $out = [];
        foreach ($rows as $r) {
            $city = (string) $r['city'];
            $n = (int) $r['sessions'];
            $rate = $n > 0 ? (int) $r['buyers'] / $n : 0.0;
            if ($city === '' || $n < max(200, 0.05 * $total) || $rate > 0.5 * $siteRate) {
                continue;
            }
            $formula = Impact::of(
                new Term(__('sesiones de :city', ['city' => $city]), $n, 'count'),
                new Term(__('de conversión por debajo del promedio'), round($siteRate - $rate, 4), 'rate'),
                new Term(__('que se recupera'), $share, 'rate', 'city_fix_share'),
                new Term(__('de ticket'), $aov, 'money'),
            );
            $out[] = new InsightCandidate(
                fingerprint: mb_strtolower($city),
                severity: 'opportunity',
                title: __(':city te visita mucho pero convierte :rate (el sitio, :site)', ['city' => $city, 'rate' => Money::percent($rate), 'site' => Money::percent($siteRate)]),
                facts: ['city' => $city, 'sessions' => $n, 'buyers' => (int) $r['buyers'], 'conversion' => round($rate, 4), 'site_conversion' => round($siteRate, 4),
                    'share_of_sessions' => round($n / $total, 4), 'aov' => round($aov, 2), 'window_days' => 30],
                assumptions: [__('Revisa el costo y el tiempo de envío a esa ciudad y los medios de pago que usan allí.')],
                action: ['label' => __('Revisa la geografía en Analítica'), 'to' => 'analitica/geografia'],
                periodStart: $since->toDateString(),
                periodEnd: $now->toDateString(),
                formula: $formula,
            );
        }
        usort($out, fn (InsightCandidate $a, InsightCandidate $b) => (float) $b->impactValue() <=> (float) $a->impactValue());

        return array_slice($out, 0, 3);
    }
}
