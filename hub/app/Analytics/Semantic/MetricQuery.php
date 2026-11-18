<?php

declare(strict_types=1);

namespace App\Analytics\Semantic;

use InvalidArgumentException;

/** Consulta estructurada sobre la capa semántica (hub/CLAUDE.md §10). La IA de la Fase 4 solo arma esto. */
final class MetricQuery
{
    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $dimensions
     */
    public function __construct(
        public readonly array $metrics,
        public readonly array $dimensions,
        public readonly Period $period,
        public readonly Filters $filters = new Filters,
        public readonly int $limit = 500,
    ) {
        foreach ($metrics as $m) {
            if (! Catalog::has($m)) {
                throw new InvalidArgumentException("Métrica desconocida: {$m}");
            }
        }
        foreach ($dimensions as $d) {
            if (! isset(Catalog::DIMENSIONS[$d])) {
                throw new InvalidArgumentException("Dimensión desconocida: {$d}");
            }
        }
        if (count($dimensions) > 2) {
            throw new InvalidArgumentException('Máximo dos dimensiones.');
        }
    }

    public function cacheKey(): string
    {
        return md5((string) json_encode([$this->metrics, $this->dimensions, $this->period->toArray(), $this->filters->toArray(), $this->limit]));
    }
}
