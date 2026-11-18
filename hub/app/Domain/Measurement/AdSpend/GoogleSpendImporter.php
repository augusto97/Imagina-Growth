<?php

declare(strict_types=1);

namespace App\Domain\Measurement\AdSpend;

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdDestination;
use App\Domain\Measurement\Support\GoogleAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Ads API: googleAds:searchStream con GAQL por grupo de anuncios y día.
 * Necesita developer token y OAuth [verificar versión de la API].
 */
final class GoogleSpendImporter implements SpendImporter
{
    public function __construct(private readonly GoogleAccessToken $tokens) {}

    public function supports(AdDestination $destination): bool
    {
        return $destination->platform === Platform::Google
            && $destination->credential('ads_customer_id') !== null
            && $destination->credential('ads_developer_token') !== null
            && $destination->credential('oauth_refresh_token') !== null;
    }

    public function fetch(AdDestination $destination, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $customer = (string) preg_replace('/\D/', '', (string) $destination->credential('ads_customer_id'));
        $url = rtrim((string) config('igr-measurement.google.ads_url'), '/').'/'.config('igr-measurement.google.ads_version')."/customers/{$customer}/googleAds:searchStream";
        $gaql = sprintf(
            "SELECT campaign.id, campaign.name, ad_group.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, customer.currency_code FROM ad_group WHERE segments.date BETWEEN '%s' AND '%s'",
            $from->toDateString(),
            $to->toDateString(),
        );
        $headers = ['developer-token' => (string) $destination->credential('ads_developer_token')];
        if ($destination->credential('ads_login_customer_id') !== null) {
            $headers['login-customer-id'] = (string) preg_replace('/\D/', '', (string) $destination->credential('ads_login_customer_id'));
        }
        $response = Http::timeout(60)->withToken($this->tokens->for($destination))->withHeaders($headers)->asJson()->post($url, ['query' => $gaql]);
        if (! $response->successful()) {
            throw new RuntimeException(__('Google Ads no entregó el gasto (HTTP :status): :msg', ['status' => $response->status(), 'msg' => (string) $response->json('0.error.message', $response->json('error.message', ''))]));
        }

        $rows = [];
        // searchStream devuelve una lista de lotes, cada uno con `results`.
        foreach ((array) $response->json() as $batch) {
            foreach ((array) ($batch['results'] ?? []) as $r) {
                $rows[] = new SpendRow(
                    (string) $r['segments']['date'],
                    (string) $r['campaign']['id'],
                    $r['campaign']['name'] ?? null,
                    isset($r['adGroup']['id']) ? (string) $r['adGroup']['id'] : null,
                    null,
                    number_format(((int) ($r['metrics']['costMicros'] ?? 0)) / 1_000_000, 2, '.', ''),
                    strtoupper((string) ($r['customer']['currencyCode'] ?? 'USD')),
                    (int) ($r['metrics']['impressions'] ?? 0),
                    (int) ($r['metrics']['clicks'] ?? 0),
                );
            }
        }

        return $rows;
    }
}
