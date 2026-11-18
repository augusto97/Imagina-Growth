<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\DetectorSkipped;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #7 Páginas lentas que pierden ventas: solo con Web Vitals (Imagina Turbo los
 * envía como `page.vitals` con `lcp_ms`). Compara la conversión de las sesiones
 * que entraron por páginas con LCP > 4 s frente a las de LCP ≤ 2,5 s. Sin esos
 * datos el detector se salta y dice por qué.
 */
final class SlowPagesDetector implements Detector
{
    public function key(): string
    {
        return 'slow_pages';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(30);
        $ch = Ch::forWorkspace($workspace);
        $pages = $ch->select(<<<'SQL'
            SELECT path, quantile(0.75)(toFloat64OrZero(toString(properties.lcp_ms))) AS lcp, count() AS samples FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'page.vitals' AND occurred_at >= {since:DateTime64(3)} AND path != ''
            GROUP BY path HAVING samples >= 20
            SQL, ['since' => Data::ch($since)]);
        if ($pages === []) {
            throw new DetectorSkipped(__('Sin datos de Web Vitals: llegan cuando el sitio usa Imagina Turbo.'));
        }
        $slow = array_values(array_filter($pages, fn ($p) => (float) $p['lcp'] > 4000));
        $fast = array_values(array_filter($pages, fn ($p) => (float) $p['lcp'] <= 2500));
        if ($slow === [] || $fast === []) {
            return [];
        }
        $conv = fn (array $paths) => $ch->select(<<<'SQL'
            SELECT count() AS sessions, countIf(purchased = 1) AS buyers FROM sessions FINAL
            WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {since:DateTime64(3)} AND has({paths:Array(String)}, landing_path)
            SQL, ['since' => Data::ch($since), 'paths' => ExportContactData::chArray(array_map(fn ($p) => (string) $p['path'], $paths))])[0] ?? ['sessions' => 0, 'buyers' => 0];
        $s = $conv($slow);
        $f = $conv($fast);
        if ((int) $s['sessions'] < 100 || (int) $f['sessions'] < 100) {
            return [];
        }
        $slowRate = (int) $s['buyers'] / (int) $s['sessions'];
        $fastRate = (int) $f['buyers'] / (int) $f['sessions'];
        if ($fastRate <= 0 || $slowRate >= 0.8 * $fastRate) {
            return [];
        }
        $aov = Data::aov($workspace, $since);
        $formula = Impact::of(
            new Term(__('sesiones que entran por páginas lentas'), (int) $s['sessions'], 'count'),
            new Term(__('de conversión de diferencia'), round($fastRate - $slowRate, 4), 'rate'),
            new Term(__('de ticket'), $aov, 'money'),
        );
        usort($slow, fn ($a, $b) => (float) $b['lcp'] <=> (float) $a['lcp']);

        return [new InsightCandidate(
            fingerprint: 'lcp',
            severity: 'warning',
            title: __(':n páginas tardan más de 4 s en cargar y convierten menos', ['n' => count($slow)]),
            facts: ['pages' => array_map(fn ($p) => ['path' => (string) $p['path'], 'lcp_ms' => round((float) $p['lcp']), 'samples' => (int) $p['samples']], array_slice($slow, 0, 10)),
                'slow_sessions' => (int) $s['sessions'], 'slow_conversion' => round($slowRate, 4), 'fast_conversion' => round($fastRate, 4), 'aov' => round($aov, 2)],
            assumptions: [__('Si las páginas lentas convirtieran como las rápidas. Revisa imágenes, caché y scripts en Imagina Turbo.')],
            action: ['label' => __('Revisa el rendimiento del sitio'), 'to' => 'sitios'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
        )];
    }
}
