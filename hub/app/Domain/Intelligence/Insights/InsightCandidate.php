<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

use App\Domain\Intelligence\Insights\Support\Impact;

/** Hallazgo propuesto por un detector, antes de guardarse. */
final class InsightCandidate
{
    /**
     * @param  array<string, mixed>  $facts  todas las cifras (las usa la IA: no inventa números)
     * @param  list<string>  $assumptions  notas del impacto, visibles y en español
     * @param  array{label: string, to?: string, url?: string, kind?: string, payload?: array<string, mixed>}|null  $action
     *                                                                                                                       `kind`: link (por defecto), activate_flow ({library_key}) o create_campaign ({system_key, name, channel}); `to` es siempre el enlace de respaldo
     * @param  Impact|null  $formula  el impacto como producto de factores (con los supuestos editables)
     * @param  int|null  $validDays  vigencia; por defecto 7 días los nocturnos y 14 los semanales
     * @param  float|null  $magnitude  para los hallazgos sin impacto en dinero: con qué se mide si empeoró
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $severity,
        public readonly string $title,
        public readonly array $facts,
        public readonly ?string $impact = null,
        public readonly array $assumptions = [],
        public readonly ?array $action = null,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
        public readonly ?Impact $formula = null,
        public readonly ?int $validDays = null,
        public readonly ?float $magnitude = null,
    ) {}

    public function impactValue(): ?float
    {
        if ($this->formula !== null) {
            return $this->formula->total();
        }

        return $this->impact !== null ? (float) $this->impact : null;
    }
}
