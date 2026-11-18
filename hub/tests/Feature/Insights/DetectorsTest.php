<?php

require_once __DIR__.'/InsightsHelpers.php';

use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => insightsSetUp($this));

function insightsFound($test, string $key): ?object
{
    insightsRun($test, [$key]);

    return insightsOf($test)->where('detector_key', $key)->first();
}

function insightsMessages($test, int $n, array $attrs): void
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = $attrs + ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'channel' => 'email', 'direction' => 'out', 'kind' => 'marketing',
            'source_type' => 'campaign', 'status' => 'sent', 'idempotency_key' => Str::random(24), 'created_at' => now()->subDays(2), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('messages')->insert($chunk);
    }
}

it('#3 encuentra productos muy vistos que casi no se venden, y calla si convierten normal', function (bool $positive) {
    $events = [];
    foreach (['1', '2', '3', '4', '5', '6'] as $pid) {
        insightsProduct($this, $pid, "Producto {$pid}", 100000);
        for ($v = 0; $v < 100; $v++) {
            $events[] = insightsEvent($this, 'product.viewed', $this->now->subDays(1 + $v % 20), [], ['product_id' => $pid]);
        }
        $orders = ($pid === '6' && $positive) ? 0 : 10;
        for ($o = 0; $o < $orders; $o++) {
            insightsItem($this, insightsOrder($this, ['created_at' => now()->subDays(2)]), $pid);
        }
    }
    insightsCh('events', $events);
    $i = insightsFound($this, 'viewed_not_bought');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    // 10 pedidos que faltan (mediana 10 %) × 25 % × $100.000.
    expect($i->facts['products'][0]['product_id'])->toBe('6')->and($i->facts['median_conversion'])->toEqual(0.1)
        ->and((float) $i->estimated_impact_ws)->toBe(250000.0)->and($i->recommended_action['to'])->toBe('analitica/productos');
})->with(['positivo' => true, 'negativo' => false]);

it('#4 encuentra productos que se abandonan en el carrito y propone activar el flujo', function (bool $positive) {
    $events = [];
    foreach (['1' => 25, '2' => 24, '3' => $positive ? 2 : 24] as $pid => $orders) {
        insightsProduct($this, (string) $pid, "Producto {$pid}", 80000);
        for ($a = 0; $a < 30; $a++) {
            $events[] = insightsEvent($this, 'cart.item_added', $this->now->subDays(1 + $a % 20), [], ['product_id' => (string) $pid]);
        }
        for ($o = 0; $o < $orders; $o++) {
            insightsItem($this, insightsOrder($this, ['created_at' => now()->subDays(2)]), (string) $pid);
        }
    }
    insightsCh('events', $events);
    $i = insightsFound($this, 'cart_abandonment_products');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['products'][0]['product_id'])->toBe('3')->and($i->facts['products'][0]['abandon_rate'])->toEqualWithDelta(0.9333, 0.0001)
        ->and($i->recommended_action['kind'])->toBe('activate_flow')->and($i->recommended_action['payload']['library_key'])->toBe('abandoned_cart')
        ->and((float) $i->estimated_impact_ws)->toBeGreaterThan(0);
})->with(['positivo' => true, 'negativo' => false]);

it('#5 lista las búsquedas frecuentes sin resultados y descarta las que parecen datos personales', function (bool $positive) {
    $events = [];
    for ($k = 0; $k < 6; $k++) {
        $events[] = insightsEvent($this, 'search.performed', $this->now->subDays(1 + $k), ['query' => 'Vinilo Azul ', 'results_count' => $positive ? 0 : 4]);
        $events[] = insightsEvent($this, 'search.performed', $this->now->subDays(1 + $k), ['query' => 'ana@ejemplo.com', 'results_count' => 0]);
    }
    for ($k = 0; $k < 3; $k++) {
        $events[] = insightsEvent($this, 'search.performed', $this->now->subDays(2), ['query' => 'rara', 'results_count' => 0]);
    }
    insightsCh('events', $events);
    $i = insightsFound($this, 'search_no_results');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['queries'])->toBe([['query' => 'vinilo azul', 'searches' => 6]])->and($i->facts['searches'])->toBe(6)
        ->and($i->title)->toContain('vinilo azul');
})->with(['positivo' => true, 'negativo' => false]);

it('#7 sin Web Vitals se salta con el motivo; con ellos compara la conversión de páginas lentas y rápidas', function () {
    insightsRun($this, ['slow_pages']);
    expect(InsightEngine::runs($this->ws)['slow_pages'])->toMatchArray(['status' => 'skipped'])
        ->and(insightsOf($this)->where('detector_key', 'slow_pages'))->toHaveCount(0);

    $events = [];
    $sessions = [];
    for ($k = 0; $k < 20; $k++) {
        $events[] = insightsEvent($this, 'page.vitals', $this->now->subDays(1 + $k % 10), ['lcp_ms' => 5200], ['path' => '/lento']);
        $events[] = insightsEvent($this, 'page.vitals', $this->now->subDays(1 + $k % 10), ['lcp_ms' => 1500], ['path' => '/rapido']);
    }
    for ($k = 0; $k < 150; $k++) {
        $sessions[] = insightsSession($this, $this->now->subDays(1 + $k % 20), $k % 50 === 0, ['landing_path' => '/lento']);
        $sessions[] = insightsSession($this, $this->now->subDays(1 + $k % 20), $k % 10 === 0, ['landing_path' => '/rapido']);
    }
    insightsCh('events', $events);
    insightsCh('sessions', $sessions);
    insightsOrder($this, ['total_ws' => 120000]);
    $i = insightsFound($this, 'slow_pages');
    expect($i)->not->toBeNull()->and($i->facts['pages'][0]['path'])->toBe('/lento')
        ->and($i->facts['slow_conversion'])->toEqual(0.02)->and($i->facts['fast_conversion'])->toEqual(0.1);
});

it('#9 propone el flujo de segunda compra para clientes de un pedido estancados', function (bool $positive) {
    for ($k = 0; $k < 12; $k++) {
        insightsContact($this, ['orders_count' => 1, 'aov_ws' => 90000, 'first_order_at' => now()->subDays($positive ? 60 : 10)]);
    }
    $i = insightsFound($this, 'first_purchase_stalled');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['customers'])->toBe(12)->and($i->facts['median_days_to_second'])->toBe(45)
        ->and((float) $i->estimated_impact_ws)->toBe(108000.0)   // 12 × 10 % × $90.000
        ->and($i->recommended_action['payload']['library_key'])->toBe('second_purchase');
})->with(['positivo' => true, 'negativo' => false]);

it('#10 avisa cuando el 10 % de los clientes concentra la mayoría de las ventas', function (bool $positive) {
    for ($k = 0; $k < 50; $k++) {
        insightsOrder($this, ['contact_id' => strtoupper((string) Str::ulid()), 'total_ws' => ($positive && $k < 5) ? 1000000 : 10000, 'created_at' => now()->subDays(20)]);
    }
    $i = insightsFound($this, 'concentration');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['top_customers'])->toBe(5)->and($i->facts['share'])->toEqualWithDelta(0.9174, 0.0001)->and($i->facts['magnitude'])->toBeGreaterThan(0.9)
        ->and($i->estimated_impact_ws)->toBeNull()->and($i->recommended_action['to'])->toBe('fidelizacion');
})->with(['positivo' => true, 'negativo' => false]);

it('#11 compara el LTV a 180 días por canal de llegada', function (bool $positive) {
    foreach (['google' => $positive ? 2 : 1, 'meta' => 1] as $channel => $orders) {
        for ($k = 0; $k < 30; $k++) {
            $cid = strtoupper((string) Str::ulid());
            insightsOrder($this, ['contact_id' => $cid, 'is_first_order' => true, 'channel_first' => $channel, 'created_at' => now()->subDays(200)]);
            if ($orders === 2) {
                insightsOrder($this, ['contact_id' => $cid, 'created_at' => now()->subDays(150)]);
            }
        }
    }
    $i = insightsFound($this, 'channel_ltv');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['best_channel'])->toBe('google')->and($i->facts['ratio'])->toEqual(2)->and($i->facts['channels'])->toHaveCount(2);
})->with(['positivo' => true, 'negativo' => false]);

it('#12 encuentra el producto de entrada cuyos clientes valen más', function (bool $positive) {
    foreach (['A' => [20, $positive ? 3 : 1], 'B' => [40, 1]] as $pid => [$customers, $orders]) {
        for ($k = 0; $k < $customers; $k++) {
            $cid = strtoupper((string) Str::ulid());
            insightsItem($this, insightsOrder($this, ['contact_id' => $cid, 'is_first_order' => true, 'created_at' => now()->subDays(250)]), $pid, "Producto {$pid}");
            for ($o = 1; $o < $orders; $o++) {
                insightsOrder($this, ['contact_id' => $cid, 'created_at' => now()->subDays(200)]);
            }
        }
    }
    $i = insightsFound($this, 'entry_product');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['product']['product_id'])->toBe('A')->and($i->facts['product']['ltv_180'])->toEqual(300000)->and($i->facts['ratio'])->toEqual(1.8);
})->with(['positivo' => true, 'negativo' => false]);

it('#13 encuentra la ciudad con mucho tráfico y poca conversión', function (bool $positive) {
    $rows = [];
    for ($k = 0; $k < 600; $k++) {
        $rows[] = insightsSession($this, $this->now->subDays(1 + $k % 25), $k % 20 === 0, ['city' => 'Bogotá']);
    }
    for ($k = 0; $k < 400; $k++) {
        $rows[] = insightsSession($this, $this->now->subDays(1 + $k % 25), $positive ? $k % 200 === 0 : $k % 25 === 0, ['city' => 'Cali']);
    }
    insightsCh('sessions', $rows);
    insightsOrder($this, ['total_ws' => 150000]);
    $i = insightsFound($this, 'city_potential');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['city'])->toBe('Cali')->and($i->facts['conversion'])->toEqual(0.005)->and($i->facts['site_conversion'])->toEqual(0.032)
        ->and($i->impact_assumptions['formula']['terms'][2]['assumption'])->toBe('city_fix_share');
})->with(['positivo' => true, 'negativo' => false]);

it('#14 detecta que cada vez más pedidos usan cupón', function (bool $positive) {
    for ($k = 0; $k < 60; $k++) {
        insightsOrder($this, ['coupon_codes' => $k < 6 ? '["BIEN10"]' : null, 'discount' => $k < 6 ? 20000 : 0, 'created_at' => now()->subDays(40 + $k)]);
    }
    for ($k = 0; $k < 40; $k++) {
        $coupon = $k < ($positive ? 20 : 4);
        insightsOrder($this, ['coupon_codes' => $coupon ? '["BIEN10"]' : '[]', 'discount' => $coupon ? 20000 : 0, 'created_at' => now()->subDays(2 + $k % 25)]);
    }
    $i = insightsFound($this, 'discount_dependency');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts['recent']['share'])->toEqual(0.5)->and($i->facts['baseline']['share'])->toEqual(0.1)
        ->and((float) $i->estimated_impact_ws)->toBe(320000.0);   // 40 pedidos × 40 % más × $20.000
})->with(['positivo' => true, 'negativo' => false]);

it('#15 no dice nada si el flujo de carrito abandonado está activo', function () {
    for ($k = 0; $k < 8; $k++) {
        DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => Str::random(20),
            'status' => 'abandoned', 'subtotal' => 100000, 'subtotal_ws' => 100000, 'currency' => 'COP', 'items_count' => 1, 'abandoned_at' => now()->subDays(1),
            'last_activity_at' => now()->subDays(1), 'created_at' => now()->subDays(1), 'updated_at' => now()]);
    }
    expect(insightsFound($this, 'essential_flow_inactive'))->not->toBeNull();
    app(CurrentWorkspace::class)->run($this->ws, fn () => Flow::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Carrito', 'status' => Flow::ACTIVE,
        'template_key' => 'abandoned_cart', 'trigger' => ['type' => 'event', 'event' => 'cart.abandoned']]));
    insightsRun($this, ['essential_flow_inactive']);
    expect(insightsOf($this)->where('detector_key', 'essential_flow_inactive')->first()->status)->toBe('expired');
});

it('#16 avisa de la calidad baja del número y de una campaña con muchos bloqueos', function (bool $positive) {
    $account = app(CurrentWorkspace::class)->run($this->ws, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp',
        'provider' => 'meta_cloud', 'display_name' => '+57 300', 'config' => [], 'status' => 'active', 'quality_rating' => $positive ? 'RED' : 'GREEN']));
    $campaign = strtoupper((string) Str::ulid());
    DB::table('campaigns')->insert(['id' => $campaign, 'workspace_id' => $this->ws->id, 'name' => 'Black Friday', 'channel' => 'whatsapp', 'status' => 'sent',
        'audience' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    insightsMessages($this, 100, ['channel' => 'whatsapp', 'campaign_id' => $campaign]);
    if ($positive) {
        DB::table('messages')->where('campaign_id', $campaign)->limit(4)->update(['status' => 'failed', 'error_code' => '131050']);
        insightsCh('events', [insightsEvent($this, 'message.unsubscribed', $this->now->subDay(), ['channel' => 'whatsapp', 'campaign_id' => $campaign], ['source_type' => 'hub'])]);
    }
    insightsRun($this, ['whatsapp_quality']);
    $found = insightsOf($this)->where('detector_key', 'whatsapp_quality');
    if (! $positive) {
        expect($found)->toHaveCount(0);

        return;
    }
    expect($found->pluck('severity')->sort()->values()->all())->toBe(['critical', 'critical'])
        ->and($found->firstWhere('fingerprint', 'whatsapp_quality:campaign:'.$campaign)->facts)->toMatchArray(['blocked' => 4, 'unsubscribed' => 1, 'rate' => 0.05]);
    expect($account->id)->not->toBeEmpty();
})->with(['positivo' => true, 'negativo' => false]);

it('#17 avisa de rebotes y quejas por encima del límite', function (bool $positive) {
    insightsMessages($this, 300, []);
    DB::table('messages')->where('workspace_id', $this->ws->id)->limit($positive ? 9 : 3)->update(['status' => 'bounced']);
    $i = insightsFound($this, 'email_reputation');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts)->toMatchArray(['sent' => 300, 'bounced' => 9, 'bounce_rate' => 0.03])->and($i->severity)->toBe('warning');
})->with(['positivo' => true, 'negativo' => false]);

it('#18 detecta fatiga: demasiados mensajes y bajas en aumento', function (bool $positive) {
    for ($c = 0; $c < 10; $c++) {
        insightsMessages($this, 7, ['contact_id' => strtoupper((string) Str::ulid())]);
    }
    $events = [];
    for ($k = 0; $k < ($positive ? 6 : 2); $k++) {
        $events[] = insightsEvent($this, 'message.unsubscribed', $this->now->subDays(2), ['channel' => 'email'], ['source_type' => 'hub']);
    }
    $events[] = insightsEvent($this, 'message.unsubscribed', $this->now->subDays(20), ['channel' => 'email'], ['source_type' => 'hub']);
    insightsCh('events', $events);
    $i = insightsFound($this, 'fatigue');
    if (! $positive) {
        expect($i)->toBeNull();

        return;
    }
    expect($i->facts)->toMatchArray(['fatigued_contacts' => 10, 'unsubscribes_recent' => 6, 'unsubscribes_before' => 1]);
})->with(['positivo' => true, 'negativo' => false]);

it('#19 avisa de conversiones fallando o con pocos datos coincidentes', function (string $case) {
    $rows = [];
    for ($k = 0; $k < 30; $k++) {
        $rows[] = ['workspace_id' => $this->ws->ch_id, 'event_id' => (string) Str::uuid(), 'platform' => 'meta',
            'status' => ($case === 'failing' && $k < 6) ? 'failed' : 'delivered', 'http_status' => 200, 'error' => ($case === 'failing' && $k < 6) ? 'Invalid token' : '',
            'error_code' => '', 'match_keys' => $case === 'few_keys' ? ['em'] : ['em', 'ph', 'fbp'], 'attempts' => 1,
            'delivered_at' => $this->now->subDays(1)->utc()->format('Y-m-d H:i:s'), 'destination_id' => 'd1', 'target' => 'pixel', 'conversion' => 'Purchase',
            'source_event' => 'order.paid', 'final' => 1, 'test' => 0];
    }
    insightsCh('conversion_deliveries', $rows);
    insightsRun($this, ['conversions_failing']);
    $found = insightsOf($this)->where('detector_key', 'conversions_failing');
    match ($case) {
        'failing' => expect($found->first()->facts)->toMatchArray(['platform' => 'meta', 'failed' => 6, 'failure_rate' => 0.2, 'sample_error' => 'Invalid token']),
        'few_keys' => expect($found->first()->facts)->toMatchArray(['avg_match_keys' => 1.0]),
        default => expect($found)->toHaveCount(0),
    };
})->with(['fallando' => 'failing', 'pocas claves' => 'few_keys', 'sano' => 'ok']);
