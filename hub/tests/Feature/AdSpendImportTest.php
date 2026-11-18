<?php

use App\Domain\Measurement\Enums\Platform;
use App\Domain\Measurement\Models\AdSpendDaily;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Http;
use Tests\Support\MeasurementFixtures;

uses(MeasurementFixtures::class);

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
});

function measurementSpendRows($test): array
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => AdSpendDaily::query()->orderBy('platform')->orderBy('date')->get(['platform', 'date', 'campaign_id', 'spend', 'currency', 'clicks'])->map(fn ($r) => [$r->platform, $r->getRawOriginal('date'), $r->campaign_id, $r->spend, $r->currency, (int) $r->clicks])->all());
}

it('importa el gasto de Meta con paginación y es idempotente', function () {
    $this->destination($this->ws, Platform::Meta);
    Http::fake([
        'graph.facebook.com/v24.0/act_998877/insights?after=X' => Http::response([
            'data' => [['date_start' => '2026-11-17', 'campaign_id' => 'c1', 'campaign_name' => 'Noviembre', 'adset_id' => 'as1', 'ad_id' => 'ad1', 'spend' => '80000', 'impressions' => '700', 'clicks' => '21', 'account_currency' => 'COP']],
        ]),
        'graph.facebook.com/v24.0/act_998877/insights*' => Http::response([
            'data' => [['date_start' => '2026-11-16', 'campaign_id' => 'c1', 'campaign_name' => 'Noviembre', 'adset_id' => 'as1', 'ad_id' => 'ad1', 'spend' => '120000.50', 'impressions' => '1000', 'clicks' => '40', 'account_currency' => 'COP']],
            'paging' => ['next' => 'https://graph.facebook.com/v24.0/act_998877/insights?after=X'],
        ]),
    ]);
    $this->artisan('igr:ad-spend:import', ['--days' => 2])->assertSuccessful();
    $this->artisan('igr:ad-spend:import', ['--days' => 2])->assertSuccessful();

    expect(measurementSpendRows($this))->toBe([
        ['meta', '2026-11-16', 'c1', '120000.50', 'COP', 40],
        ['meta', '2026-11-17', 'c1', '80000.00', 'COP', 21],
    ]);
});

it('importa el gasto de Google Ads (searchStream) y de TikTok', function () {
    $this->destination($this->ws, Platform::Google);
    $this->destination($this->ws, Platform::TikTok);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
        'googleads.googleapis.com/*' => Http::response([['results' => [
            ['campaign' => ['id' => '11', 'name' => 'Búsqueda'], 'adGroup' => ['id' => '22'], 'segments' => ['date' => '2026-11-17'], 'metrics' => ['costMicros' => '35500000', 'impressions' => '900', 'clicks' => '30'], 'customer' => ['currencyCode' => 'COP']],
        ]]]),
        'business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => ['list' => [
            ['dimensions' => ['ad_id' => 'a9', 'stat_time_day' => '2026-11-17 00:00:00'], 'metrics' => ['spend' => '15.30', 'impressions' => '500', 'clicks' => '9', 'campaign_id' => 't1', 'campaign_name' => 'TikTok nov', 'adgroup_id' => 'g1', 'currency' => 'USD']],
        ], 'page_info' => ['total_page' => 1]]]),
    ]);
    $this->artisan('igr:ad-spend:import', ['--days' => 1])->assertSuccessful();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'googleads.googleapis.com') && $r->hasHeader('developer-token', 'devtoken') && str_contains($r['query'], 'metrics.cost_micros'));
    expect(measurementSpendRows($this))->toBe([
        ['google', '2026-11-17', '11', '35.50', 'COP', 30],
        ['tiktok', '2026-11-17', 't1', '15.30', 'USD', 9],
    ]);
});

it('deja el error en el destino si la plataforma falla', function () {
    $meta = $this->destination($this->ws, Platform::Meta);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Unsupported get request']], 400)]);
    $this->artisan('igr:ad-spend:import')->assertFailed();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => $meta->fresh())->last_error)->toContain('Meta no entregó el gasto');
});
