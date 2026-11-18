<?php

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Commerce\Data\RecommendationContext;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Recommendations\Engine\RecommendationEngine;
use App\Domain\Recommendations\Stats\AssociationRulesBuilder;
use App\Domain\Recommendations\Stats\ProductStatsBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Recommendations\Support\CommerceFixtures;

uses(CommerceFixtures::class);

// M16: motor de recomendaciones (modulos.md M16, inteligencia.md §4.5).
beforeEach(function () {
    $this->commerceSetUp();
    $this->reco = fn (string $kind, array $ctx = [], int $limit = 4) => $this->commerceRun(
        fn () => app(RecommendationEngine::class)->recommendWithSource($kind, new RecommendationContext(...['siteId' => $this->site->id] + $ctx), $limit)
    );
    $this->ids = fn (array $result) => array_column($result['products'], 'id');
    $this->build = function (bool $rules = false): void {
        $this->commerceRun(function () use ($rules) {
            app(ProductStatsBuilder::class)->build($this->ws, $this->site);
            if ($rules) {
                app(AssociationRulesBuilder::class)->build($this->ws, $this->site);
            }
        });
    };
});

it('registra el motor como el servicio de recomendaciones', function () {
    expect(app(RecommendationService::class))->toBeInstanceOf(RecommendationEngine::class);
});

it('recomienda los más vendidos con stock, publicados y sin variaciones, de la tienda o de una categoría', function () {
    $this->commerceProduct('1', 'Pintura Azul', ['12']);
    $this->commerceProduct('2', 'Rodillo', ['12'], ['stock_status' => 'outofstock']);
    $this->commerceProduct('3', 'Brocha', ['12'], ['status' => 'draft']);
    $this->commerceProduct('4', 'Azul 1/4', ['12'], ['parent_external_id' => '1']);
    $this->commerceProduct('5', 'Martillo', ['30']);
    $this->commerceProduct('6', 'Lija', ['12'], ['stock_status' => 'onbackorder']);
    $this->commerceOrder(null, ['1' => 5, '2' => 50, '3' => 40, '4' => 30, '5' => 2], 10);
    $this->commerceOrder(null, ['5' => 100], 120); // fuera de los 90 días
    $this->commerceOrder(null, ['6' => 99], 3, paid: false); // sin pagar no cuenta
    ($this->build)();

    $all = ($this->reco)('best_sellers_category');
    expect(($this->ids)($all))->toBe(['1', '5', '6'])->and($all['fallback'])->toBeFalse();
    expect(($this->ids)(($this->reco)('best_sellers_category', ['categoryId' => '12'])))->toBe(['1', '6']);
    // En la ficha del Martillo (categoría 30, sin más productos): los más vendidos de la tienda, sin el Martillo.
    $fallback = ($this->reco)('best_sellers_category', ['productId' => '5']);
    expect($fallback['fallback'])->toBeTrue()->and(($this->ids)($fallback))->toBe(['1', '6']);
    // Excluir y limitar.
    expect(($this->ids)(($this->reco)('best_sellers_category', ['excludeProductIds' => ['1']], 1)))->toBe(['5']);

    $card = $all['products'][0];
    expect($card)->toMatchArray(['id' => '1', 'name' => 'Pintura Azul', 'price' => '10000.00', 'currency' => 'COP', 'image' => 'https://tienda.co/img/1.jpg', 'url' => 'https://tienda.co/p/1', 'rating_count' => 0])
        ->and($card)->toHaveKeys(['regular_price', 'sale_price', 'rating_avg']);
    expect(DB::table('products')->where('external_id', '1')->value('purchases_90d'))->toBe(5);
});

it('usa la calificación de las reseñas publicadas y, si no hay, la de WooCommerce', function () {
    $this->commerceProduct('1', 'Pintura Azul', ['12'], ['rating_avg' => 4.1, 'rating_count' => 9]);
    $this->commerceProduct('2', 'Rodillo', ['12'], ['rating_avg' => 3.5, 'rating_count' => 2]);
    foreach ([5, 4, 5, 1] as $i => $rating) {
        DB::table('reviews')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_product_id' => '1',
            'rating' => $rating, 'status' => $i === 3 ? 'pending' : 'published', 'source' => 'request', 'created_at' => now(), 'updated_at' => now()]);
    }
    ($this->build)();
    $cards = collect(($this->reco)('best_sellers_category')['products'])->keyBy('id');
    expect($cards['1']['rating_avg'])->toBe(4.67)->and($cards['1']['rating_count'])->toBe(3)
        ->and($cards['2']['rating_avg'])->toBe(3.5)->and($cards['2']['rating_count'])->toBe(2);
});

it('calcula soporte, confianza y lift de las reglas de asociación', function () {
    // 10 pedidos: A en 6, B en 5, A y B juntos en 4.
    $rule = AssociationRulesBuilder::rule(4, 6, 5, 10);
    expect($rule['support'])->toBe(0.4)
        ->and(round($rule['confidence'], 4))->toBe(0.6667)
        ->and(round($rule['lift'], 4))->toBe(1.3333);
    expect(AssociationRulesBuilder::rule(0, 6, 5, 10))->toBeNull();
});

it('arma «comprados juntos» con las reglas que pasan los umbrales, y sin reglas cae a la categoría', function () {
    foreach (['A' => '12', 'B' => '12', 'C' => '12', 'D' => '30', 'E' => '12'] as $id => $cat) {
        $this->commerceProduct($id, "Producto {$id}", [$cat]);
    }
    // A+B en 4 pedidos, A+C en 2 (no pasa el mínimo de 3), D solo en 4, E solo en 1.
    foreach (range(1, 4) as $i) {
        $this->commerceOrder(null, ['A' => 1, 'B' => 1]);
        $this->commerceOrder(null, ['D' => 1]);
    }
    foreach (range(1, 2) as $i) {
        $this->commerceOrder(null, ['A' => 1, 'C' => 1]);
    }
    $this->commerceOrder(null, ['E' => 1]);
    ($this->build)(rules: true);

    $rows = DB::table('product_associations')->where('site_id', $this->site->id)->orderBy('product_id')->get();
    expect($rows->map(fn ($r) => $r->product_id.'→'.$r->related_product_id)->all())->toBe(['A→B', 'B→A']);
    $ab = $rows->firstWhere('product_id', 'A');
    // 11 pedidos; A en 6, B en 4, juntos en 4: soporte 0,3636; confianza 0,6667; lift 1,8333.
    expect((float) $ab->support)->toBe(0.363636)->and((float) $ab->confidence)->toBe(0.666667)->and((float) $ab->lift)->toBe(1.8333)->and($ab->pair_orders)->toBe(4);

    $together = ($this->reco)('bought_together', ['productId' => 'A']);
    expect(($this->ids)($together))->toBe(['B'])->and($together['fallback'])->toBeFalse();
    // Si B se agota, A no tiene reglas útiles: más vendidos de su categoría (sin A).
    DB::table('products')->where('external_id', 'B')->update(['stock_status' => 'outofstock']);
    $fallback = ($this->reco)('bought_together', ['productId' => 'A']);
    expect($fallback['fallback'])->toBeTrue()->and(($this->ids)($fallback))->toBe(['C', 'E']);
});

it('recomienda «para ti» por afinidad sin lo que ya compró y, sin historial, los más vendidos', function () {
    $ana = $this->commerceContact(['first_name' => 'Ana']);
    $this->commerceProduct('1', 'Pintura Azul', ['12']);
    $this->commerceProduct('2', 'Rodillo', ['12']);
    $this->commerceProduct('3', 'Martillo', ['30']);
    $this->commerceProduct('4', 'Bandeja', ['12']);
    $this->commerceOrder($ana->id, ['1' => 1], 5);
    $this->commerceOrder(null, ['3' => 20, '4' => 3], 5);
    ($this->build)();

    $forAna = ($this->reco)('para_ti', ['contactId' => $ana->id]);
    // Categoría 12 sin la Pintura que ya compró; la Bandeja vende más que el Rodillo.
    expect(($this->ids)($forAna))->toBe(['4', '2'])->and($forAna['fallback'])->toBeFalse();

    $nobody = ($this->reco)('para_ti', ['anonymousId' => 'anon-nuevo-123']);
    expect($nobody['fallback'])->toBeTrue()->and(($this->ids)($nobody))->toBe(['3', '4', '1', '2']);
});

it('muestra lo que vio el visitante, del más reciente al más viejo y sin el producto actual', function () {
    $this->useIngestInfrastructure();
    foreach (['1' => 'instock', '2' => 'instock', '3' => 'outofstock', '4' => 'instock'] as $id => $stock) {
        $this->commerceProduct($id, "P{$id}", ['12'], ['stock_status' => $stock]);
    }
    $events = [];
    foreach (['1' => 50, '3' => 40, '2' => 30, '4' => 20] as $pid => $minutes) {
        $events[] = $this->envelope($this->site, 'product.viewed', ['product_id' => (string) $pid, 'name' => "P{$pid}", 'price' => '10000.00', 'currency' => 'COP'],
            ['identity' => ['anonymous_id' => 'anon-visto-12345'], 'occurred_at' => now()->subMinutes($minutes)->toIso8601String()]);
    }
    $this->postSigned($this->site, $this->secret, $events)->assertSuccessful();
    $this->runWorker();

    $seen = ($this->reco)('recently_viewed', ['anonymousId' => 'anon-visto-12345', 'productId' => '4']);
    expect(($this->ids)($seen))->toBe(['2', '1'])->and($seen['fallback'])->toBeFalse();
    // Otro navegador del mismo contacto también cuenta.
    $contact = $this->commerceContact();
    $this->commerceRun(fn () => ContactIdentity::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $contact->id, 'type' => 'anonymous_id', 'value' => 'anon-visto-12345', 'last_seen_at' => now()]));
    expect(($this->ids)(($this->reco)('recently_viewed', ['contactId' => $contact->id])))->toBe(['4', '2', '1']);
    expect(($this->reco)('recently_viewed', ['anonymousId' => 'anon-otro-99999'])['products'])->toBe([]);
});

it('marca la tendencia de la semana frente a las 4 anteriores y sin ventas recientes cae a los más vendidos', function () {
    $this->commerceProduct('1', 'Siempre vende', ['12']);
    $this->commerceProduct('2', 'Despega', ['12']);
    $this->commerceProduct('3', 'Una sola', ['12']);
    foreach ([10, 17, 24, 31] as $days) {
        $this->commerceOrder(null, ['1' => 4], $days);
    }
    $this->commerceOrder(null, ['1' => 4, '2' => 6, '3' => 1], 2);
    ($this->build)();

    $trend = ($this->reco)('trending');
    // Despega: 6 / (0 + 1) = 6; Siempre vende: 4 / (16/4 + 1) = 0,8; «Una sola» no llega al mínimo de 2 unidades.
    expect(($this->ids)($trend))->toBe(['2', '1'])->and($trend['fallback'])->toBeFalse();
    expect((float) DB::table('products')->where('external_id', '2')->value('trend_score'))->toBe(6.0);

    DB::table('order_items')->delete();
    ($this->build)();
    $quiet = ($this->reco)('trending');
    expect($quiet['fallback'])->toBeTrue()->and($quiet['products'])->toHaveCount(3);
});

it('completa el carrito con lo que se compra junto con lo que ya tiene, incluso leyendo el carrito del visitante', function () {
    foreach (['A', 'B', 'C', 'Z'] as $id) {
        $this->commerceProduct($id, "Producto {$id}", [$id === 'Z' ? '30' : '12']);
    }
    foreach (range(1, 3) as $i) {
        $this->commerceOrder(null, ['A' => 1, 'B' => 1]);
        $this->commerceOrder(null, ['Z' => 1]);
    }
    ($this->build)(rules: true);

    expect(($this->ids)(($this->reco)('complete_cart', ['cartProductIds' => ['A']])))->toBe(['B']);
    DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => 'ct-1',
        'anonymous_id' => 'anon-carrito-1', 'items' => json_encode([['product_id' => 'B', 'qty' => 1]]), 'items_count' => 1, 'status' => 'active',
        'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(($this->ids)(($this->reco)('complete_cart', ['anonymousId' => 'anon-carrito-1'])))->toBe(['A']);
    // Sin reglas: más vendidos de la categoría del carrito, sin lo que ya está en él.
    $fallback = ($this->reco)('complete_cart', ['cartProductIds' => ['C']]);
    expect($fallback['fallback'])->toBeTrue()->and(($this->ids)($fallback))->toBe(['A', 'B']);
});

it('rechaza tipos desconocidos y no mezcla productos de otro espacio de trabajo', function () {
    expect(fn () => ($this->reco)('lo_que_sea'))->toThrow(InvalidArgumentException::class);
    $this->commerceProduct('1', 'Mío');
    $other = $this->workspaceFor($this->userWith2fa(), 'Otra tienda');
    [$otherSite] = $this->connectedSite($other, 'otra.co');
    $this->commerceProduct('2', 'Ajeno', ['12'], [], $otherSite);
    ($this->build)();
    expect(($this->ids)(($this->reco)('best_sellers_category')))->toBe(['1']);
    // El sitio de otro espacio de trabajo no existe dentro de este.
    expect($this->commerceRun(fn () => app(RecommendationEngine::class)->recommend('trending', new RecommendationContext(siteId: $otherSite->id))))->toBe([]);
});
