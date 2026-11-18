<?php

declare(strict_types=1);

namespace App\Domain\Measurement\AdSpend;

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TikTok Business API: report/integrated/get, informe básico por anuncio y día
 * [verificar nombres de métricas y paginación].
 */
final class TikTokSpendImporter implements SpendImporter
{
    public function supports(AdDestination $destination): bool
    {
        return $destination->platform === Platform::TikTok
            && $destination->credential('advertiser_id') !== null
            && ($destination->credential('ads_access_token') ?? $destination->credential('access_token')) !== null;
    }

    public function fetch(AdDestination $destination, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $page = 1;
        do {
            $response = Http::timeout(30)
                ->withHeaders(['Access-Token' => (string) ($destination->credential('ads_access_token') ?? $destination->credential('access_token'))])
                ->get((string) config('igr-measurement.tiktok.report_url'), [
                    'advertiser_id' => $destination->credential('advertiser_id'),
                    'report_type' => 'BASIC',
                    'data_level' => 'AUCTION_AD',
                    'dimensions' => json_encode(['ad_id', 'stat_time_day']),
                    'metrics' => json_encode(['spend', 'impressions', 'clicks', 'campaign_id', 'campaign_name', 'adgroup_id', 'currency']),
                    'start_date' => $from->toDateString(),
                    'end_date' => $to->toDateString(),
                    'page' => $page,
                    'page_size' => 1000,
                ]);
            if (! $response->successful() || (int) $response->json('code', -1) !== 0) {
                throw new RuntimeException(__('TikTok no entregó el gasto: :msg', ['msg' => (string) $response->json('message', 'HTTP '.$response->status())]));
            }
            foreach ((array) $response->json('data.list', []) as $r) {
                $m = (array) ($r['metrics'] ?? []);
                $d = (array) ($r['dimensions'] ?? []);
                $rows[] = new SpendRow(
                    substr((string) ($d['stat_time_day'] ?? ''), 0, 10),
                    (string) ($m['campaign_id'] ?? ''),
                    isset($m['campaign_name']) ? (string) $m['campaign_name'] : null,
                    isset($m['adgroup_id']) ? (string) $m['adgroup_id'] : null,
                    isset($d['ad_id']) ? (string) $d['ad_id'] : null,
                    number_format((float) ($m['spend'] ?? 0), 2, '.', ''),
                    strtoupper((string) ($m['currency'] ?? 'USD')),
                    (int) ($m['impressions'] ?? 0),
                    (int) ($m['clicks'] ?? 0),
                );
            }
            $totalPages = (int) $response->json('data.page_info.total_page', 1);
            $page++;
        } while ($page <= $totalPages && $page < 200);

        return $rows;
    }
}
