<?php

declare(strict_types=1);

namespace App\Domain\Measurement\AdSpend;

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta Marketing API: GET /act_{id}/insights por anuncio y día, con paginación.
 * Usa ads_access_token (o el token de CAPI si tiene ads_read) [verificar permisos].
 */
final class MetaSpendImporter implements SpendImporter
{
    public function supports(AdDestination $destination): bool
    {
        return $destination->platform === Platform::Meta
            && $destination->credential('ad_account_id') !== null
            && ($destination->credential('ads_access_token') ?? $destination->credential('access_token')) !== null;
    }

    public function fetch(AdDestination $destination, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $account = (string) $destination->credential('ad_account_id');
        $account = str_starts_with($account, 'act_') ? $account : 'act_'.$account;
        $url = rtrim((string) config('igr-measurement.meta.graph_url'), '/').'/'.config('igr-measurement.meta.graph_version').'/'.$account.'/insights';
        $query = [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'fields' => 'campaign_id,campaign_name,adset_id,ad_id,spend,impressions,clicks,account_currency',
            'limit' => 500,
        ];
        $token = (string) ($destination->credential('ads_access_token') ?? $destination->credential('access_token'));
        $rows = [];
        $next = $url;
        $pages = 0;
        while ($next !== null && $pages++ < 200) {
            // Las páginas siguientes traen su query en la URL: no se pasa otra (Laravel la reemplazaría).
            $response = Http::timeout(30)->withToken($token)->get($next, $pages === 1 ? $query : null);
            if (! $response->successful()) {
                throw new RuntimeException(__('Meta no entregó el gasto (HTTP :status): :msg', ['status' => $response->status(), 'msg' => (string) $response->json('error.message', '')]));
            }
            foreach ((array) $response->json('data', []) as $r) {
                $rows[] = new SpendRow(
                    (string) $r['date_start'],
                    (string) $r['campaign_id'],
                    $r['campaign_name'] ?? null,
                    $r['adset_id'] ?? null,
                    $r['ad_id'] ?? null,
                    number_format((float) ($r['spend'] ?? 0), 2, '.', ''),
                    strtoupper((string) ($r['account_currency'] ?? 'USD')),
                    (int) ($r['impressions'] ?? 0),
                    (int) ($r['clicks'] ?? 0),
                );
            }
            $next = $response->json('paging.next');
            $next = is_string($next) ? $next : null;
        }

        return $rows;
    }
}
