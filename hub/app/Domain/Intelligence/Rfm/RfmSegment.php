<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Rfm;

/**
 * Los 11 segmentos RFM de inteligencia.md §3, en el orden en que se evalúan
 * (gana la primera regla). El valor es la clave que se guarda en
 * contacts.rfm_segment y que usan los segmentos del sistema (M04).
 */
enum RfmSegment: string
{
    case Champions = 'champions';
    case Loyal = 'loyal';
    case CantLose = 'cant_lose';
    case AtRisk = 'at_risk';
    case PotentialLoyal = 'potential_loyal';
    case New = 'new';
    case Promising = 'promising';
    case NeedAttention = 'need_attention';
    case AboutToSleep = 'about_to_sleep';
    case Hibernating = 'hibernating';
    case Lost = 'lost';

    public static function classify(int $r, int $f, int $m): self
    {
        return match (true) {
            $r >= 4 && $f >= 4 => self::Champions,
            $r >= 3 && $f >= 3 => self::Loyal,
            $r <= 2 && $f >= 4 => self::CantLose,
            $r <= 2 && ($f === 3 || ($f === 2 && $m >= 4)) => self::AtRisk,
            $r >= 4 && $f === 2 => self::PotentialLoyal,
            $r === 5 && $f === 1 => self::New,
            $r === 4 && $f === 1 => self::Promising,
            $r === 3 && $f <= 2 => self::NeedAttention,
            $r === 2 && $f <= 2 => self::AboutToSleep,
            $r === 1 && $f === 2 => self::Hibernating,
            default => self::Lost,
        };
    }

    /** Acción sugerida (inteligencia.md §3), clave de traducción en la SPA. */
    public function isValuableAtRisk(): bool
    {
        return in_array($this, [self::CantLose, self::AtRisk], true);
    }
}
