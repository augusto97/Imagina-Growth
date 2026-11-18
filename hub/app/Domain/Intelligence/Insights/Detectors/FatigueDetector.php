<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #18 Fatiga: contactos con más de N mensajes de marketing por semana (en las
 * últimas 2) y bajas en aumento (al menos 5 y un 20 % más que las 2 semanas
 * anteriores).
 */
final class FatigueDetector implements Detector
{
    public function key(): string
    {
        return 'fatigue';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(14);
        $perWeek = (int) config('igr-insights.thresholds.fatigue_per_week', 3);
        $fatigued = DB::query()->fromSub(DB::table('messages')->where('workspace_id', $workspace->id)->where('direction', 'out')->where('kind', 'marketing')
            ->where('created_at', '>=', $since->utc())->whereNotNull('contact_id')
            ->groupBy('contact_id')->havingRaw('COUNT(*) > ?', [2 * $perWeek])->selectRaw('contact_id'), 'f')->count();
        if ($fatigued < 10) {
            return [];
        }
        $rows = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT countIf(occurred_at >= {since:DateTime64(3)}) AS recent, countIf(occurred_at < {since:DateTime64(3)}) AS before FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'message.unsubscribed' AND occurred_at >= {from:DateTime64(3)}
            SQL, ['since' => Data::ch($since), 'from' => Data::ch($since->subDays(14))]);
        $recent = (int) ($rows[0]['recent'] ?? 0);
        $before = (int) ($rows[0]['before'] ?? 0);
        if ($recent < 5 || $recent < 1.2 * $before) {
            return [];
        }
        $marketing = DB::table('contact_consents')->where('workspace_id', $workspace->id)->where('purpose', 'marketing')->where('status', 'granted')
            ->distinct()->count('contact_id');

        return [new InsightCandidate(
            fingerprint: 'frequency',
            severity: 'warning',
            title: __(':n contactos reciben más de :per mensajes por semana y las bajas subieron', ['n' => Money::number($fatigued), 'per' => $perWeek]),
            facts: ['fatigued_contacts' => $fatigued, 'per_week_limit' => $perWeek, 'unsubscribes_recent' => $recent, 'unsubscribes_before' => $before,
                'marketing_audience' => $marketing, 'window_days' => 14],
            assumptions: [__('Baja la frecuencia, excluye de las campañas a quien está en un flujo y prioriza a quien interactúa.')],
            action: ['label' => __('Revisa los límites de frecuencia'), 'to' => 'consentimiento'],
            periodStart: $since->toDateString(),
            periodEnd: $now->toDateString(),
            magnitude: (float) $fatigued,
        )];
    }
}
