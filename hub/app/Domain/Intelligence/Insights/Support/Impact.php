<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Support;

use App\Domain\Intelligence\Insights\Money;

/**
 * Impacto estimado como producto de factores visibles (inteligencia.md §5.2):
 * «37 clientes × 18 % de reactivación de referencia × $185.000 de ticket =
 * $1.232.100». Siempre conservador y nunca una promesa: cada supuesto se puede
 * editar y el total se recalcula.
 */
final class Impact
{
    /** @param list<Term> $terms */
    public function __construct(public readonly array $terms) {}

    public static function of(Term ...$terms): self
    {
        return new self(array_values($terms));
    }

    public function total(): float
    {
        $total = 1.0;
        foreach ($this->terms as $t) {
            $total *= $t->value;
        }

        return round(max(0.0, $total), 2);
    }

    /**
     * El mismo cálculo con otros valores para los supuestos.
     *
     * @param  array<string, float>  $assumptions  clave => valor
     */
    public function with(array $assumptions): self
    {
        return new self(array_map(fn (Term $t) => $t->assumption !== null && array_key_exists($t->assumption, $assumptions)
            ? new Term($t->label, (float) $assumptions[$t->assumption], $t->unit, $t->assumption)
            : $t, $this->terms));
    }

    public function text(string $currency): string
    {
        $parts = array_map(fn (Term $t) => trim(self::format($t, $currency).' '.$t->label), $this->terms);

        return implode(' × ', $parts).' = '.Money::format($this->total(), $currency);
    }

    /** @return array{terms: list<array{label: string, value: float, unit: string, assumption: string|null}>, total: float} */
    public function toArray(): array
    {
        return ['terms' => array_map(fn (Term $t) => $t->toArray(), $this->terms), 'total' => $this->total()];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(array_values(array_map(fn ($t) => Term::fromArray((array) $t), (array) ($row['terms'] ?? []))));
    }

    public static function format(Term $t, string $currency): string
    {
        return match ($t->unit) {
            'rate' => Money::percent($t->value, fmod(round($t->value * 100, 4), 1.0) == 0.0 ? 0 : 1),
            'money' => Money::format($t->value, $currency),
            'count' => fmod($t->value, 1.0) == 0.0 ? Money::number($t->value) : number_format($t->value, 1, ',', '.'),
            'days' => number_format($t->value, fmod($t->value, 1.0) == 0.0 ? 0 : 1, ',', '.'),
            default => number_format($t->value, fmod($t->value, 1.0) == 0.0 ? 0 : 2, ',', '.'),
        };
    }
}
