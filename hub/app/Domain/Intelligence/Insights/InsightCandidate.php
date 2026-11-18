<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

/** Hallazgo propuesto por un detector, antes de guardarse. */
final class InsightCandidate
{
    /**
     * @param  array<string, mixed>  $facts  todas las cifras (las usará la IA de la Fase 4: no inventa números)
     * @param  list<string>  $assumptions  supuestos del impacto, visibles y en español
     * @param  array{label: string, to?: string, url?: string}|null  $action
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
    ) {}
}
