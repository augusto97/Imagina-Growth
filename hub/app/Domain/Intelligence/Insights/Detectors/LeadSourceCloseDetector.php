<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Impact;
use App\Domain\Intelligence\Insights\Support\Term;
use App\Domain\Services\Leads\Support\LeadFacts;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #23 Cierre por fuente (M18): la fuente que más leads trae frente a la que más
 * leads ganados deja (90 días). Si no son la misma y la de más volumen cierra a la
 * mitad o menos, es un hallazgo: mover presupuesto o calificar mejor esa fuente.
 * Dinero en juego = leads de la fuente de volumen × diferencia de tasa de cierre ×
 * valor promedio de un lead ganado.
 */
final class LeadSourceCloseDetector implements Detector
{
    private const MIN_LEADS = 5;

    public function key(): string
    {
        return 'lead_source_close';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(90);
        $rows = DB::table('leads')->where('workspace_id', $workspace->id)->where('created_at', '>=', $since->utc())
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(source, '$.channel')), 'unknown') AS channel, COUNT(*) AS leads, SUM(won_at IS NOT NULL) AS won")
            ->groupBy('channel')->get()
            ->map(fn ($r) => ['channel' => (string) $r->channel, 'leads' => (int) $r->leads, 'won' => (int) $r->won, 'rate' => (int) $r->leads > 0 ? (int) $r->won / (int) $r->leads : 0.0])
            ->filter(fn (array $r) => $r['channel'] !== 'unknown' && $r['leads'] >= self::MIN_LEADS)->values()->all();
        if (count($rows) < 2) {
            return [];
        }
        usort($rows, fn (array $a, array $b) => [$b['leads'], $b['won']] <=> [$a['leads'], $a['won']]);
        $volume = $rows[0];
        $best = $rows;
        usort($best, fn (array $a, array $b) => [$b['won'], $b['rate']] <=> [$a['won'], $a['rate']]);
        $closer = $best[0];
        if ($closer['channel'] === $volume['channel'] || $closer['won'] < 2 || $volume['rate'] > $closer['rate'] / 2) {
            return [];
        }
        $value = LeadStats::avgWonValue($workspace, $since, $now);
        $formula = $value > 0 ? Impact::of(
            new Term(__('leads de :source', ['source' => self::label($volume['channel'])]), $volume['leads'], 'count'),
            new Term(__('de diferencia en la tasa de cierre'), round($closer['rate'] - $volume['rate'], 4), 'rate'),
            new Term(__('de valor por lead ganado'), $value, 'money'),
        ) : null;

        return [new InsightCandidate(
            fingerprint: $volume['channel'].':'.$closer['channel'],
            severity: 'opportunity',
            title: __(':volume trae más leads (:n), pero :closer cierra más: :rate frente a :vrate', [
                'volume' => self::label($volume['channel']), 'n' => Money::number($volume['leads']), 'closer' => self::label($closer['channel']),
                'rate' => Money::percent($closer['rate']), 'vrate' => Money::percent($volume['rate']),
            ]),
            facts: [
                'volume_source' => $volume['channel'], 'volume_leads' => $volume['leads'], 'volume_won' => $volume['won'], 'volume_close_rate' => round($volume['rate'], 4),
                'best_source' => $closer['channel'], 'best_leads' => $closer['leads'], 'best_won' => $closer['won'], 'best_close_rate' => round($closer['rate'], 4),
                'avg_won_value' => round($value, 2), 'window_days' => 90,
            ],
            assumptions: [__('Es el máximo si los leads de la fuente de más volumen cerraran como los de la mejor; revisa la segmentación de esos anuncios o califícalos antes de llamarlos.')],
            action: ['label' => __('Mira el cierre por fuente en Leads'), 'to' => 'leads?tab=kpis'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            formula: $formula,
            magnitude: (float) $volume['leads'],
        )];
    }

    private static function label(string $channel): string
    {
        return LeadFacts::channelLabel($channel) ?? $channel;
    }
}
