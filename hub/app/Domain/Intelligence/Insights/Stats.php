<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

/** Estadística de apoyo para los detectores. */
final class Stats
{
    /**
     * Prueba z de dos proporciones, una cola (¿bajó la proporción reciente?).
     *
     * @return array{z: float, p: float}
     */
    public static function proportionDrop(int $baseHits, int $baseN, int $recentHits, int $recentN): array
    {
        if ($baseN === 0 || $recentN === 0) {
            return ['z' => 0.0, 'p' => 1.0];
        }
        $p1 = $baseHits / $baseN;
        $p2 = $recentHits / $recentN;
        $pooled = ($baseHits + $recentHits) / ($baseN + $recentN);
        $se = sqrt($pooled * (1 - $pooled) * (1 / $baseN + 1 / $recentN));
        if ($se == 0.0) {
            return ['z' => 0.0, 'p' => 1.0];
        }
        $z = ($p1 - $p2) / $se;

        return ['z' => $z, 'p' => 1 - self::normalCdf($z)];
    }

    /** Φ(x) con la aproximación de Abramowitz y Stegun (error < 7,5e-8). */
    public static function normalCdf(float $x): float
    {
        $t = 1 / (1 + 0.2316419 * abs($x));
        $d = 0.3989422804014327 * exp(-$x * $x / 2);
        $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));

        return $x >= 0 ? 1 - $p : $p;
    }
}
