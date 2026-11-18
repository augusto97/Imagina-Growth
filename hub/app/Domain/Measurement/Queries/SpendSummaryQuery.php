<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Queries;

use App\Domain\Measurement\Models\AdSpendDaily;
use Carbon\CarbonImmutable;

/** Gasto importado por plataforma, campaña y día (lo usa también el tablero de Canales). */
final class SpendSummaryQuery
{
    /** @return array<string, mixed> */
    public function handle(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = fn () => AdSpendDaily::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_platform' => $base()->groupBy('platform', 'currency')
                ->selectRaw('platform, currency, SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(clicks) AS clicks')
                ->orderByDesc('spend')->get()
                ->map(fn ($r) => ['platform' => $r->platform, 'currency' => $r->currency, 'spend' => number_format((float) $r->getAttribute('spend'), 2, '.', ''), 'impressions' => (int) $r->getAttribute('impressions'), 'clicks' => (int) $r->getAttribute('clicks')])->values(),
            'by_campaign' => $base()->groupBy('platform', 'campaign_id', 'campaign_name', 'currency')
                ->selectRaw('platform, campaign_id, campaign_name, currency, SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(clicks) AS clicks')
                ->orderByDesc('spend')->limit(50)->get()
                ->map(fn ($r) => ['platform' => $r->platform, 'campaign_id' => $r->campaign_id, 'campaign_name' => $r->campaign_name, 'currency' => $r->currency, 'spend' => number_format((float) $r->getAttribute('spend'), 2, '.', ''), 'impressions' => (int) $r->getAttribute('impressions'), 'clicks' => (int) $r->getAttribute('clicks')])->values(),
            'daily' => $base()->groupBy('date', 'platform', 'currency')
                ->selectRaw('date, platform, currency, SUM(spend) AS spend')
                ->orderBy('date')->get()
                ->map(fn ($r) => ['date' => CarbonImmutable::parse((string) $r->getRawOriginal('date'))->toDateString(), 'platform' => $r->platform, 'currency' => $r->currency, 'spend' => number_format((float) $r->getAttribute('spend'), 2, '.', '')])->values(),
        ];
    }
}
