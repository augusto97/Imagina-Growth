<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Support;

use App\Domain\Intelligence\Insights\DetectorCatalog;
use App\Domain\Intelligence\Models\Insight;

/** Un hallazgo para la SPA y para la IA, con su fórmula de impacto ya escrita. */
final class InsightPresenter
{
    /** @return array<string, mixed> */
    public static function make(Insight $i, string $currency): array
    {
        [$formula, $notes] = self::assumptions($i);
        $entry = DetectorCatalog::find((string) $i->detector_key);

        return [
            'id' => $i->publicId(),
            'detector' => $i->detector_key,
            'number' => $entry['number'] ?? null,
            'category' => $entry['category'] ?? 'sales',
            'severity' => $i->severity,
            'title' => $i->title,
            'facts' => $i->facts,
            'impact' => $i->estimated_impact_ws,
            'formula' => $formula === null ? null : $formula->toArray() + ['text' => $formula->text($currency)],
            'assumptions' => $notes,
            'action' => $i->recommended_action,
            'status' => $i->status,
            'period_start' => $i->getAttribute('period_start')?->toDateString(),
            'period_end' => $i->getAttribute('period_end')?->toDateString(),
            'valid_until' => $i->getAttribute('valid_until')?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    /**
     * La fórmula del impacto y las notas. Los hallazgos de la Fase 1 guardaban solo
     * una lista de textos.
     *
     * @return array{0: Impact|null, 1: list<string>}
     */
    public static function assumptions(Insight $i): array
    {
        $raw = $i->impact_assumptions;
        if (! is_array($raw)) {
            return [null, []];
        }
        $notes = [];
        foreach ((array_is_list($raw) ? $raw : (array) ($raw['notes'] ?? [])) as $note) {
            if (is_scalar($note)) {
                $notes[] = (string) $note;
            }
        }
        if (array_is_list($raw)) {
            return [null, $notes];
        }
        $formula = is_array($raw['formula'] ?? null) && ($raw['formula']['terms'] ?? []) !== [] ? Impact::fromArray($raw['formula']) : null;

        return [$formula, $notes];
    }
}
