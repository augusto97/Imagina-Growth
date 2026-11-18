<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Data;

final class NarrativeResult
{
    /**
     * @param  'ai'|'fallback'  $source
     * @param  array<string, mixed>|null  $json  la salida estructurada validada, si se pidió
     * @param  array<string, mixed>  $validation  intentos, cifras revisadas y desconocidas, motivo del respaldo
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $source,
        public readonly array $validation,
        public readonly ?string $model,
        public readonly AiUsage $usage,
    ) {}

    public function fromAi(): bool
    {
        return $this->source === 'ai';
    }
}
