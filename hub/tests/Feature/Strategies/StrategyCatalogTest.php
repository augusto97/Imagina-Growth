<?php

use App\Domain\Flows\Models\Flow;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * Estrategias (docs/diseno/rediseno-v2.md §6): el catálogo con estado, requisitos, potencial y resultado.
 */

beforeEach(function () {
    $this->strategyWorld();
});

it('lista el catálogo completo con su estado', function () {
    $res = $this->actingAs($this->owner)->getJson($this->strategies())->assertOk()->json();
    expect($res['data'])->not->toBeEmpty();
    $cart = $this->strategyIn($res, 'abandoned_cart');
    expect($cart['status'])->toBe('available');

    $act = $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk()->json();
    expect($act['status'])->toBe('active')
        ->and($this->inWs(fn () => Flow::query()->where('template_key', 'abandoned_cart')->value('status')))->toBe('active');
});
