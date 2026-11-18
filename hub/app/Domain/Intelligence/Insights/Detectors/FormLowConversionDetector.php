<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #22 Formulario con baja conversión (M18): páginas con formulario cuya tasa de
 * envío (formularios ÷ vistas, 28 días) está por debajo de la mitad del promedio
 * de las páginas con formulario del sitio. Dinero en juego = vistas × diferencia
 * con el promedio × tasa de cierre de los leads × valor promedio de un lead ganado.
 */
final class FormLowConversionDetector implements Detector
{
    private const MIN_VIEWS = 200;

    public function key(): string
    {
        return 'form_low_conversion';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(28);
        $rows = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT path, countIf(event = 'page.viewed') AS views, countIf(event = 'form.submitted') AS forms
            FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event IN ('page.viewed', 'form.submitted')
              AND occurred_at >= {since:DateTime64(3)} AND occurred_at < {until:DateTime64(3)} AND path != ''
            GROUP BY path HAVING forms > 0 OR path IN (
                SELECT DISTINCT path FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'form.submitted' AND occurred_at >= {since90:DateTime64(3)})
            ORDER BY views DESC LIMIT 200
            SQL, ['since' => Data::ch($since), 'until' => Data::ch($now), 'since90' => Data::ch($now->subDays(90))]);
        $pages = array_values(array_filter(array_map(fn (array $r) => ['path' => mb_substr((string) $r['path'], 0, 300), 'views' => (int) $r['views'], 'forms' => (int) $r['forms']], $rows), fn (array $p) => $p['views'] > 0));
        $views = array_sum(array_column($pages, 'views'));
        $forms = array_sum(array_column($pages, 'forms'));
        if ($views === 0 || $forms === 0 || count($pages) < 2) {
            return [];
        }
        $avg = $forms / $views;
        $worst = null;
        foreach ($pages as $p) {
            $rate = $p['forms'] / $p['views'];
            if ($p['views'] >= self::MIN_VIEWS && $rate < $avg / 2 && ($worst === null || $p['views'] * ($avg - $rate) > $worst['views'] * ($avg - $worst['rate']))) {
                $worst = $p + ['rate' => $rate];
            }
        }
        if ($worst === null) {
            return [];
        }
        $gapLeads = $worst['views'] * ($avg - $worst['rate']);
        $close = LeadStats::closeRate($workspace, $now->subDays(180), $now);
        $value = LeadStats::avgWonValue($workspace, $now->subDays(180), $now);
        $formula = $close > 0 && $value > 0 ? Impact::of(
            new Term(__('vistas de la página'), $worst['views'], 'count'),
            new Term(__('de diferencia con el promedio de tus formularios'), round($avg - $worst['rate'], 4), 'rate'),
            new Term(__('de cierre de los leads'), round($close, 4), 'rate'),
            new Term(__('de valor por lead ganado'), $value, 'money'),
        ) : null;

        return [new InsightCandidate(
            fingerprint: 'page:'.md5($worst['path']),
            severity: 'opportunity',
            title: __('El formulario de :path convierte :rate de las visitas; tus otros formularios, :avg', [
                'path' => $worst['path'], 'rate' => Money::percent($worst['rate']), 'avg' => Money::percent($avg),
            ]),
            facts: [
                'path' => $worst['path'], 'views' => $worst['views'], 'forms' => $worst['forms'], 'rate' => round($worst['rate'], 4),
                'site_form_rate' => round($avg, 4), 'missing_leads' => round($gapLeads, 1), 'close_rate_180d' => round($close, 4), 'avg_won_value' => round($value, 2), 'window_days' => 28,
            ],
            assumptions: [__('Si esta página convirtiera como el promedio de tus formularios; prueba un formulario más corto, un botón de WhatsApp o un mensaje más claro.')],
            action: ['label' => __('Revisa la página en Analítica → Servicios'), 'to' => 'analitica/servicios'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
            magnitude: $gapLeads,
        )];
    }
}
