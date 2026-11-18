<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Data;

final class AiUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly float $costUsd = 0.0,
        public readonly int $requests = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
            $this->costUsd + $other->costUsd,
            $this->requests + $other->requests,
        );
    }
}
