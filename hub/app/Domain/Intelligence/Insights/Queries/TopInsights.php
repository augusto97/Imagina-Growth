<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Queries;

use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Intelligence\Insights\DetectorCatalog;
use App\Domain\Intelligence\Insights\Support\InsightPresenter;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Los hallazgos abiertos más importantes como hechos para la IA (el analista
 * semanal, inteligencia.md §6.2): primero los de mayor impacto en dinero, luego
 * por severidad. Toda cifra que la IA escriba sobre ellos tiene que estar aquí;
 * los textos libres van sin datos personales.
 */
final class TopInsights
{
    /**
     * Cada hallazgo: id, detector, number, category, severity, title, impact (número o null),
     * impact_formula (texto o null), assumptions (textos), facts (las cifras), action {label, to} y
     * period {start, end}.
     *
     * @return list<array<string, mixed>>
     */
    public static function facts(Workspace $workspace, int $limit = 5): array
    {
        $currency = (string) ($workspace->currency ?: 'COP');

        $out = Insight::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereIn('status', ['new', 'seen'])
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->get()
            ->sortByDesc(fn (Insight $i) => [(float) ($i->estimated_impact_ws ?? 0), Insight::SEVERITY_ORDER[$i->severity] ?? 0])
            ->take(max(1, $limit))
            ->map(function (Insight $i) use ($currency): array {
                [$formula, $notes] = InsightPresenter::assumptions($i);
                $action = $i->recommended_action;

                return [
                    'id' => $i->publicId(),
                    'detector' => (string) $i->detector_key,
                    'number' => DetectorCatalog::find((string) $i->detector_key)['number'] ?? null,
                    'category' => DetectorCatalog::category((string) $i->detector_key),
                    'severity' => (string) $i->severity,
                    'title' => PiiScrubber::scrub((string) $i->title),
                    'impact' => $i->estimated_impact_ws === null ? null : round((float) $i->estimated_impact_ws, 2),
                    'impact_formula' => $formula?->text($currency),
                    'assumptions' => PiiScrubber::scrub($notes),
                    'facts' => PiiScrubber::scrub((array) $i->facts),
                    'action' => is_array($action) ? ['label' => (string) ($action['label'] ?? ''), 'to' => isset($action['to']) ? (string) $action['to'] : null] : null,
                    'period' => ['start' => $i->getAttribute('period_start')?->toDateString(), 'end' => $i->getAttribute('period_end')?->toDateString()],
                ];
            })->values()->all();

        return array_values($out);
    }
}
