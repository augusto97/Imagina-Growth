<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Actions;

use App\Domain\Intelligence\Insights\Models\InsightAssumption;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Intelligence\Insights\Support\InsightPresenter;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Validation\ValidationException;

/**
 * Cambia (o devuelve a su valor por defecto) un supuesto del impacto y recalcula
 * al instante los hallazgos abiertos que lo usan (inteligencia.md §5.2).
 */
final class UpdateAssumption
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly Assumptions $assumptions,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @return int hallazgos recalculados */
    public function handle(string $key, ?float $value, string $userId): int
    {
        $def = config("igr-insights.assumptions.{$key}");
        if (! is_array($def)) {
            throw ValidationException::withMessages(['key' => __('Ese supuesto no existe.')]);
        }
        if ($value !== null && ($value < (float) $def['min'] || $value > (float) $def['max'])) {
            throw ValidationException::withMessages(['value' => __('Usa un valor entre :min y :max.', [
                'min' => rtrim(rtrim(number_format((float) $def['min'] * 100, 1, ',', '.'), '0'), ',').' %',
                'max' => rtrim(rtrim(number_format((float) $def['max'] * 100, 1, ',', '.'), '0'), ',').' %',
            ])]);
        }
        $before = $this->assumptions->value($key);
        if ($value === null) {
            InsightAssumption::query()->where('key', $key)->delete();
        } else {
            InsightAssumption::query()->updateOrCreate(['key' => $key], ['workspace_id' => $this->current->id(), 'value' => $value, 'updated_by' => $userId]);
        }
        $this->assumptions->forget();
        $now = $this->assumptions->value($key);
        $this->audit->handle('insight.assumption_changed', null, ['key' => $key, 'from' => $before, 'to' => $now], $this->current->id(), null, $userId);

        $updated = 0;
        foreach (Insight::query()->whereIn('status', ['new', 'seen'])->get() as $insight) {
            [$formula, $notes] = InsightPresenter::assumptions($insight);
            if ($formula === null || ! collect($formula->terms)->contains(fn ($t) => $t->assumption === $key)) {
                continue;
            }
            $next = $formula->with([$key => $now]);
            $facts = (array) $insight->facts;
            if (array_key_exists($key, $facts)) {
                $facts[$key] = $now;
            }
            $insight->forceFill(['estimated_impact_ws' => (string) $next->total(), 'facts' => $facts,
                'impact_assumptions' => ['formula' => $next->toArray(), 'notes' => $notes]])->save();
            $updated++;
        }

        return $updated;
    }
}
