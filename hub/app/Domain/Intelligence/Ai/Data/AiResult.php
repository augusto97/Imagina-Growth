<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Data;

final class AiResult
{
    /**
     * @param  array<string, mixed>|null  $json  la salida estructurada, si se pidió un esquema
     * @param  list<array{name: string, input: array<string, mixed>, output: array<string, mixed>|null, error: string|null}>  $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $model,
        public readonly ?string $stopReason,
        public readonly AiUsage $usage,
        public readonly array $toolCalls = [],
    ) {}

    /** El modelo (y su respaldo) se negó a responder por política. */
    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    /**
     * Todo lo que devolvieron las herramientas: los hechos con que se valida la respuesta.
     *
     * @return list<array<string, mixed>>
     */
    public function toolFacts(): array
    {
        return array_values(array_filter(array_map(fn (array $c) => $c['output'], $this->toolCalls)));
    }
}
