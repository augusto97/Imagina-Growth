<?php

declare(strict_types=1);

namespace App\Domain\Measurement\AdSpend;

/** Una fila de gasto diario normalizada. */
final class SpendRow
{
    public function __construct(
        public readonly string $date,
        public readonly string $campaignId,
        public readonly ?string $campaignName,
        public readonly ?string $adsetId,
        public readonly ?string $adId,
        public readonly string $spend,
        public readonly string $currency,
        public readonly int $impressions,
        public readonly int $clicks,
    ) {}
}
