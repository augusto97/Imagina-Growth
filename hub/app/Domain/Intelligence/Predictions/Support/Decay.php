<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Support;

/** Peso con decaimiento exponencial: vale 1 hoy y la mitad a los `halfLife` días. */
final class Decay
{
    public static function weight(int $at, int $now, float $halfLifeDays): float
    {
        $days = max(0, $now - $at) / 86400;

        return 0.5 ** ($days / max(1.0, $halfLifeDays));
    }
}
