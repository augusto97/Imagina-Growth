<?php

use App\Analytics\Ch;
use App\Analytics\Dashboards\GeographyDashboard;
use Illuminate\Support\Str;

/*
 * F12 (docs/auditoria-2026-11.md): la conversión por ciudad no engaña. Se comparan las ciudades sin tildes ni «D.C.»,
 * no sale con pocas visitas y se dice qué parte de las visitas no tiene ciudad.
 */

it('compara las ciudades sin tildes ni «D.C.»', function () {
    expect(GeographyDashboard::cityKey('Bogotá D.C.'))->toBe('bogota')
        ->and(GeographyDashboard::cityKey('BOGOTA'))->toBe('bogota')
        ->and(GeographyDashboard::cityKey('Bogotá, Distrito Capital'))->toBe('bogota')
        ->and(GeographyDashboard::cityKey('Medellín'))->toBe('medellin')
        ->and(GeographyDashboard::cityKey('  '))->toBe('');
});

it('muestra la conversión solo con visitas suficientes y cuenta las visitas sin ciudad', function () {
    $this->useIngestInfrastructure();
    config(['igr.analytics.cache' => false]);
    $owner = $this->userWith2fa();
    $ws = $this->workspaceFor($owner);
    [$site, $secret] = $this->connectedSite($ws);

    $order = fn (string $id, string $city, string $state) => $this->envelope($site, 'order.paid', ['order' => $this->orderSnapshot([
        'order_id' => $id, 'number' => $id, 'created_at' => now()->subDays(2)->toIso8601String(), 'paid_at' => now()->subDays(2)->toIso8601String(),
        'billing' => ['city' => $city, 'state' => $state], 'shipping' => ['city' => $city, 'state' => $state],
    ])]);
    $this->postSigned($site, $secret, [$order('501', 'Bogotá D.C.', 'CO-DC'), $order('502', 'Bogotá D.C.', 'CO-DC'), $order('503', 'Pereira', 'CO-RIS')])->assertStatus(202);
    $this->runWorker();

    $rows = [];
    $add = function (string $city, int $n, int $purchased) use (&$rows, $ws, $site): void {
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'workspace_id' => $ws->ch_id, 'site_id' => $site->ch_id, 'session_id' => (string) Str::uuid(), 'anonymous_id' => 'a'.$i, 'contact_id' => '',
                'started_at' => now()->subDays(3)->format('Y-m-d H:i:s.v'), 'ended_at' => now()->subDays(3)->addMinutes(5)->format('Y-m-d H:i:s.v'),
                'pageviews' => 3, 'channel' => 'direct', 'device_type' => 'mobile', 'city' => $city, 'purchased' => $i < $purchased ? 1 : 0, 'revenue' => '0',
            ];
        }
    };
    $add('Bogota', 150, 3);   // la IP dice «Bogota»; el pedido, «Bogotá D.C.»
    $add('Pereira', 20, 1);   // muy pocas visitas: 5 % no dice nada
    $add('', 30, 0);          // sin ciudad
    app(Ch::class)->insert('sessions', $rows);

    $data = $this->actingAs($owner)->getJson("/api/w/{$ws->publicId()}/analytics/geography")->assertOk()->json('data');
    $cities = collect($data['cities'])->keyBy('city');

    expect($cities['Bogotá D.C.'])->toMatchArray(['orders' => 2, 'sessions' => 150, 'conversion' => 0.02, 'low_sample' => false])
        ->and($cities['Pereira'])->toMatchArray(['orders' => 1, 'sessions' => 20, 'conversion' => null, 'low_sample' => true])
        ->and($data['sessions'])->toMatchArray(['total' => 200, 'without_city' => 30, 'without_city_share' => 0.15])
        ->and($data['min_sessions'])->toBe(GeographyDashboard::MIN_SESSIONS);
});
