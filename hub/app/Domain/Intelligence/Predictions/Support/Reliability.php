<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Support;

use App\Domain\Intelligence\Predictions\Models\PredictionRun;

/**
 * «Qué tan confiable es» (inteligencia.md §4): un nivel por modelo a partir de su
 * precisión contra el periodo de reserva. `null` si el modelo no tiene validación.
 */
final class Reliability
{
    /** @return 'high'|'medium'|'low'|null */
    public static function level(?PredictionRun $run): ?string
    {
        if ($run === null || $run->status !== 'completed') {
            return null;
        }
        $m = (array) $run->metrics;

        return match ($run->model) {
            'clv' => self::fromError((float) abs((float) ($m['purchases']['error_pct'] ?? 999)), $m['purchases']['correlation'] ?? null),
            'churn' => isset($m['auc']) ? ((float) $m['auc'] >= 0.75 ? 'high' : ((float) $m['auc'] >= 0.65 ? 'medium' : 'low')) : null,
            'edno' => isset($m['within_15_days_pct']) ? ((float) $m['within_15_days_pct'] >= 50 ? 'high' : ((float) $m['within_15_days_pct'] >= 30 ? 'medium' : 'low')) : null,
            default => null,
        };
    }

    /** @return 'high'|'medium'|'low' */
    private static function fromError(float $errorPct, mixed $correlation): string
    {
        $corr = is_numeric($correlation) ? (float) $correlation : 0.0;
        if ($errorPct <= 15 && $corr >= 0.4) {
            return 'high';
        }

        return $errorPct <= 30 ? 'medium' : 'low';
    }
}
