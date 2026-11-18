<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltySettlement;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/LoyaltyFixtures.php';

/*
 * Puntos de los pedidos de punta a punta por la ingesta (M13, «Antifraude»):
 * pendientes al pagarse, confirmados al completarse y pasar el periodo de
 * devolución, revertidos con reembolsos o cancelaciones; niveles VIP con
 * `customer.set_tier` hacia la tienda.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $this->program = loyaltyProgram($this->ws);
    $this->tiers = loyaltyTiers($this->ws, $this->program);
    loyaltyFakeSite();
});

function loyaltyOrderEvent($test, string $event, array $props, ?string $at = null): array
{
    return $test->envelope($test->site, $event, $props, $at !== null ? ['occurred_at' => CarbonImmutable::parse($at)->toIso8601String()] : []);
}

function loyaltyPay($test, string $id, string $subtotal, array $extra = [], ?string $at = null): void
{
    $total = number_format((float) $subtotal + 12000, 2, '.', '');
    $test->postSigned($test->site, $test->secret, [loyaltyOrderEvent($test, 'order.paid', ['order' => $test->orderSnapshot(array_replace_recursive([
        'order_id' => $id, 'number' => $id, 'coupons' => [], 'paid_at' => now()->toIso8601String(), 'modified_at' => now()->toIso8601String(),
        'totals' => ['subtotal' => $subtotal, 'discount' => '0.00', 'shipping' => '12000.00', 'total' => $total],
        'items' => [['item_id' => $id.'1', 'line_total' => $subtotal, 'qty' => 1, 'unit_price' => $subtotal]],
    ], $extra))], $at)]);
    $test->runWorker();
}

function loyaltyContactOf($test): Contact
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Contact::query()->whereNull('merged_into_id')->firstOrFail());
}

it('da puntos pendientes al pagarse, los confirma tras el periodo de devolución y sube de nivel', function () {
    loyaltyPay($this, '1234', '270000.00');
    $contact = loyaltyContactOf($this);
    loyaltyIn($this->ws, function ($ledger) use ($contact) {
        $s = $ledger->summary($contact->id);
        expect($s['points'])->toBe(0)->and($s['pending'])->toBe(270);   // 282.000 − 12.000 de envío = 270 puntos
    });
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->find($contact->id)->tier_id))->toBe($this->tiers[0]->id);
    Http::assertSent(fn (Request $r) => ($r->data()['command'] ?? null) === 'customer.set_tier' && $r->data()['args']['customer_ref'] === 'wc:77' && $r->data()['args']['tier_name'] === 'Bronce' && ! isset($r->data()['args']['role']));

    // Completado: se confirma 15 días después, no antes.
    $this->postSigned($this->site, $this->secret, [loyaltyOrderEvent($this, 'order.completed', ['order_id' => '1234', 'order' => $this->orderSnapshot(['order_id' => '1234', 'status' => 'completed', 'coupons' => [], 'modified_at' => now()->toIso8601String(), 'totals' => ['subtotal' => '270000.00', 'discount' => '0.00', 'total' => '282000.00']])])]);
    $this->runWorker();
    $this->artisan('igr:loyalty:settle', ['--now' => now()->addDays(10)->toIso8601String()])->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->pointsBalance($contact->id))->toBe(0));
    $this->travelTo(now()->addDays(16));
    $this->artisan('igr:loyalty:settle')->assertSuccessful();
    loyaltyIn($this->ws, function ($ledger) use ($contact) {
        expect($ledger->pointsBalance($contact->id))->toBe(270)->and($ledger->summary($contact->id)['pending'])->toBe(0)
            ->and(LoyaltySettlement::query()->first()->status)->toBe('done');
    });
    $rows = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'status') AS s FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'points.earned' ORDER BY occurred_at");
    expect(array_column($rows, 's'))->toBe(['pending', 'confirmed']);

    // Segundo pedido grande: con Bronce (x1) da 600 y lo sube a Plata (con su rol de WooCommerce).
    loyaltyPay($this, '2000', '600000.00');
    $tier = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->find($contact->id)->tier_id);
    expect($tier)->toBe($this->tiers[1]->id);
    Http::assertSent(fn (Request $r) => ($r->data()['command'] ?? null) === 'customer.set_tier' && $r->data()['args']['tier_name'] === 'Plata' && $r->data()['args']['role'] === 'cliente_plata' && $r->data()['args']['tier_key'] === $this->tiers[1]->publicId());
    $rows = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'to_name') AS t, JSONExtractString(toJSONString(properties), 'direction') AS d FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'tier.changed' ORDER BY occurred_at");
    expect($rows)->toBe([['t' => 'Bronce', 'd' => 'up'], ['t' => 'Plata', 'd' => 'up']]);

    // Ya en Plata (x1,5): 100.000 dan 150 puntos.
    loyaltyPay($this, '3000', '100000.00');
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->summary($contact->id)['pending'])->toBe(750));
    $this->travelBack();
});

it('un reembolso parcial antes de confirmar revierte la parte proporcional y uno total lo revierte todo', function () {
    loyaltyPay($this, '5000', '588000.00');   // total 600.000 → 588 puntos
    $contact = loyaltyContactOf($this);
    $this->postSigned($this->site, $this->secret, [loyaltyOrderEvent($this, 'order.refunded', ['order_id' => '5000', 'refund_id' => '71', 'amount' => '150000.00', 'is_partial' => true,
        'order' => $this->orderSnapshot(['order_id' => '5000', 'coupons' => [], 'modified_at' => now()->addMinute()->toIso8601String(), 'totals' => ['subtotal' => '588000.00', 'discount' => '0.00', 'total' => '600000.00', 'refunded' => '150000.00']])])]);
    $this->runWorker();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->summary($contact->id)['pending'])->toBe(441));   // 588 − 25%

    // El mismo reembolso otra vez (reintento del outbox) no cambia nada.
    $this->postSigned($this->site, $this->secret, [loyaltyOrderEvent($this, 'order.refunded', ['order_id' => '5000', 'refund_id' => '71', 'amount' => '150000.00', 'is_partial' => true])]);
    $this->runWorker();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->summary($contact->id)['pending'])->toBe(441));

    $this->postSigned($this->site, $this->secret, [loyaltyOrderEvent($this, 'order.refunded', ['order_id' => '5000', 'refund_id' => '72', 'amount' => '450000.00', 'is_partial' => false,
        'order' => $this->orderSnapshot(['order_id' => '5000', 'status' => 'refunded', 'coupons' => [], 'modified_at' => now()->addMinutes(2)->toIso8601String(), 'totals' => ['subtotal' => '588000.00', 'discount' => '0.00', 'total' => '600000.00', 'refunded' => '600000.00']])])]);
    $this->runWorker();
    loyaltyIn($this->ws, function ($ledger) use ($contact) {
        expect($ledger->summary($contact->id)['pending'])->toBe(0)->and($ledger->pointsBalance($contact->id))->toBe(0)
            ->and(LoyaltySettlement::query()->first()->status)->toBe('cancelled');
    });
    // El nivel se recalcula sin ese pedido: ya no hay gasto, pero Bronce pide 0.
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->find($contact->id)->tier_id))->toBe($this->tiers[0]->id);
});

it('un reembolso después de confirmar resta del saldo y una cancelación revierte lo pendiente', function () {
    loyaltyPay($this, '6000', '200000.00');
    $contact = loyaltyContactOf($this);
    $settlement = app(CurrentWorkspace::class)->run($this->ws, fn () => LoyaltySettlement::query()->firstOrFail());
    $this->travelTo($settlement->due_at->addMinute());   // sin «completado»: se confirma con el respaldo de 30 + 15 días
    $this->artisan('igr:loyalty:settle')->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->pointsBalance($contact->id))->toBe(200));

    $this->postSigned($this->site, $this->secret, [loyaltyOrderEvent($this, 'order.refunded', ['order_id' => '6000', 'refund_id' => '90', 'amount' => '106000.00', 'is_partial' => true])]);
    $this->runWorker();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->pointsBalance($contact->id))->toBe(100));   // la mitad de 212.000

    loyaltyPay($this, '6001', '50000.00');
    $this->postSigned($this->site, $this->secret, [loyaltyOrderEvent($this, 'order.cancelled', ['order_id' => '6001'])]);
    $this->runWorker();
    loyaltyIn($this->ws, function ($ledger) use ($contact) {
        expect($ledger->summary($contact->id)['pending'])->toBe(0)->and($ledger->pointsBalance($contact->id))->toBe(100)
            ->and(LedgerEntry::query()->where('ref_id', app(CurrentWorkspace::class)->run($this->ws, fn () => Order::query()->where('external_id', '6001')->value('id')))->where('status', 'reversed')->count())->toBe(1);
    });
    $this->travelBack();
});

it('los multiplicadores de categoría y las campañas de puntos dobles se aplican al pagar', function () {
    $this->program->forceFill(['earn_rules' => array_replace($this->program->earn_rules, [
        'category_multipliers' => [['category_id' => '12', 'multiplier' => 2]],
        'campaigns' => [['name' => 'Black Friday', 'multiplier' => 2, 'starts_on' => now('America/Bogota')->toDateString(), 'ends_on' => now('America/Bogota')->addDay()->toDateString()]],
    ])])->save();
    app(DatabaseLoyaltyLedger::class)->forgetProgram();
    // Dos ítems: 100.000 en la categoría 12 (x2) y 100.000 sin multiplicador; campaña x2.
    loyaltyPay($this, '7000', '200000.00', ['items' => [
        ['item_id' => '1', 'product_id' => '88', 'name' => 'A', 'category_ids' => ['12'], 'qty' => 1, 'unit_price' => '100000.00', 'line_total' => '100000.00'],
        ['item_id' => '2', 'product_id' => '89', 'name' => 'B', 'category_ids' => ['30'], 'qty' => 1, 'unit_price' => '100000.00', 'line_total' => '100000.00'],
    ]]);
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->summary(loyaltyContactOf($this)->id)['pending'])->toBe(600));   // (200 + 100) x 2
});

it('acredita el cashback como saldo cuando los puntos del pedido se confirman', function () {
    $this->program->forceFill(['wallet_rules' => ['cashback_pct' => '5']])->save();
    app(DatabaseLoyaltyLedger::class)->forgetProgram();
    loyaltyPay($this, '8000', '100000.00');
    $contact = loyaltyContactOf($this);
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($contact->id))->toBe('0.00'));
    $due = app(CurrentWorkspace::class)->run($this->ws, fn () => LoyaltySettlement::query()->firstOrFail()->due_at);
    $this->artisan('igr:loyalty:settle', ['--now' => $due->addMinute()->toIso8601String()])->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->walletBalance($contact->id))->toBe('5000.00'));
    $rows = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'reason') AS r FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'wallet.credited'");
    expect(array_column($rows, 'r'))->toBe(['cashback']);
});

it('los pedidos importados (backfill) no dan puntos', function () {
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '9000', 'coupons' => []])], ['source' => ['type' => 'import']])]);
    $this->runWorker();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => LedgerEntry::query()->count()))->toBe(0);
});
