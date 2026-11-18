<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Attribution;

/** Un punto de contacto: el canal y las UTM con que llegó una visita. */
final class Touch
{
    public function __construct(
        public readonly string $channel,
        public readonly ?string $utmSource = null,
        public readonly ?string $utmMedium = null,
        public readonly ?string $utmCampaign = null,
        public readonly ?string $at = null,
        public readonly string $source = 'tracker', // tracker | order_context | wc_attribution
    ) {}

    /** @return array<string, string|null> */
    public function utm(): array
    {
        return array_filter(['source' => $this->utmSource, 'medium' => $this->utmMedium, 'campaign' => $this->utmCampaign], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> forma guardada en contacts.first_source / first_order_source */
    public function toArray(): array
    {
        return array_filter([
            'channel' => $this->channel,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'at' => $this->at,
            'via' => $this->source,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
