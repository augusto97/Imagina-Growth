<?php

use App\Domain\Sites\Queries\ReconciliationQuery;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

it('devuelve los hashes del catálogo paginados para la conciliación nocturna', function () {
    $events = [];
    foreach (['10', '11', '12'] as $id) {
        $events[] = $this->envelope($this->site, 'catalog.product_upserted', [
            'product_id' => $id, 'type' => 'simple', 'name' => "Producto {$id}", 'price' => '1000.00',
            'stock_status' => 'instock', 'status' => 'publish', 'hash' => str_repeat($id[1], 16),
        ]);
    }
    $events[] = $this->envelope($this->site, 'catalog.product_deleted', ['product_id' => '12']);
    $this->postSigned($this->site, $this->secret, array_slice($events, 0, 3));
    $this->runWorker();
    $this->postSigned($this->site, $this->secret, [$events[3]]);
    $this->runWorker();

    $page1 = $this->signedJson('GET', '/v1/s/catalog/hashes?type=product&limit=1', $this->site, $this->secret)->assertOk();
    expect($page1->json('items'))->toBe([['id' => '10', 'hash' => '0000000000000000']])->and($page1->json('next_cursor'))->toBe('10');
    $page2 = $this->signedJson('GET', '/v1/s/catalog/hashes?type=product&limit=1&cursor=10', $this->site, $this->secret)->assertOk();
    expect($page2->json('items.0.id'))->toBe('11')->and($page2->json('next_cursor'))->toBeNull(); // el 12 se borró
});

it('guarda el progreso del backfill y concilia por estado contra WooCommerce', function () {
    $orders = [
        ['order_id' => '1', 'status' => 'completed', 'totals' => ['total' => '100000.00']],
        ['order_id' => '2', 'status' => 'completed', 'totals' => ['total' => '250000.50']],
        ['order_id' => '3', 'status' => 'processing', 'totals' => ['total' => '80000.00', 'refunded' => '10000.00']],
        ['order_id' => '4', 'status' => 'cancelled', 'totals' => ['total' => '50000.00']],
    ];
    $events = array_map(fn ($o) => $this->envelope($this->site, 'order.created', ['order' => $this->orderSnapshot($o + ['created_at' => '2025-06-01T10:00:00-05:00', 'modified_at' => '2025-06-02T10:00:00-05:00'])], ['source' => ['type' => 'import']]), $orders);
    $this->postSigned($this->site, $this->secret, $events)->assertJsonPath('accepted', 4);
    $this->runWorker();

    $state = [
        'status' => 'completed', 'from' => '2024-11-18', 'phase' => 'done',
        'started_at' => '2026-11-18T10:00:00Z', 'finished_at' => '2026-11-18T10:05:00Z',
        'entities' => ['orders' => ['total' => 4, 'sent' => 4], 'products' => ['total' => 1, 'sent' => 1]],
        'reconciliation' => [
            'currency' => 'COP', 'generated_at' => '2026-11-18T10:05:00Z',
            'orders_by_status' => [
                'completed' => ['count' => 2, 'total' => '350000.50'],
                'processing' => ['count' => 1, 'total' => '80000.00'],
                'cancelled' => ['count' => 1, 'total' => '50000.00'],
            ],
            'refunds' => ['count' => 1, 'total' => '10000.00'],
        ],
    ];
    $this->signedJson('PUT', '/v1/s/backfill', $this->site, $this->secret, $state)->assertOk();

    $rec = app(ReconciliationQuery::class)->handle($this->site->fresh());
    expect($rec['ok'])->toBeTrue()
        ->and($rec['totals']['hub_count'])->toBe(4)
        ->and($rec['totals']['wc_total'])->toBe('480000.50')
        ->and($rec['totals']['hub_total'])->toBe('480000.50')
        ->and($rec['totals']['refunds_ok'])->toBeTrue();

    $detail = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/sites/{$this->site->publicId()}")->assertOk();
    expect($detail->json('reconciliation.ok'))->toBeTrue()
        ->and($detail->json('site.backfill.progress'))->toEqual(1)
        ->and($detail->json('site.backfill.status'))->toBe('completed');

    // Un pedido que falta en el hub rompe la conciliación y se ve en qué estado.
    $state['reconciliation']['orders_by_status']['completed']['count'] = 3;
    $this->signedJson('PUT', '/v1/s/backfill', $this->site, $this->secret, $state)->assertOk();
    $rec = app(ReconciliationQuery::class)->handle($this->site->fresh());
    expect($rec['ok'])->toBeFalse()
        ->and(collect($rec['rows'])->firstWhere('status', 'completed')['ok'])->toBeFalse();
});

it('valida el estado del backfill', function () {
    $this->signedJson('PUT', '/v1/s/backfill', $this->site, $this->secret, ['status' => 'otro'])->assertUnprocessable();
    $this->signedJson('PUT', '/v1/s/backfill', $this->site, 'sk_mal', ['status' => 'running'])->assertUnauthorized();
});
