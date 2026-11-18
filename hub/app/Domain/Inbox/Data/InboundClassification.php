<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Data;

final class InboundClassification
{
    public const INTENTS = ['purchase', 'support', 'complaint', 'optout', 'other'];

    public const SENTIMENTS = ['positive', 'neutral', 'negative'];

    /**
     * @param  'purchase'|'support'|'complaint'|'optout'|'other'  $intent
     * @param  'positive'|'neutral'|'negative'  $sentiment
     * @param  'heuristic'|'ai'  $source
     */
    public function __construct(
        public readonly string $intent,
        public readonly string $sentiment,
        public readonly float $confidence,
        public readonly string $source,
    ) {}

    public function optOut(): bool
    {
        return $this->intent === 'optout';
    }

    /** @return array{intent: string, sentiment: string, confidence: float, source: string} */
    public function toArray(): array
    {
        return ['intent' => $this->intent, 'sentiment' => $this->sentiment, 'confidence' => $this->confidence, 'source' => $this->source];
    }
}
