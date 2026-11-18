<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Ingest\Events\EventIngested;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

function inWs($test, callable $fn)
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

it('proyecta el pedido, crea el contacto y escribe en ClickHouse sin datos personales', function () {
    $product = $this->envelope($this->site, 'catalog.product_upserted', [
        'product_id' => '91', 'parent_id' => '88', 'type' => 'variation', 'sku' => 'PNT-AZ-1G', 'name' => 'Pintura Azul 1 galón',
        'price' => '150000.00', 'stock_status' => 'instock', 'status' => 'publish', 'hash' => 'abc123abc123abc1', 'category_ids' => ['12'],
    ]);
    $created = $this->envelope($this->site, 'order.created', ['order' => $this->orderSnapshot(['status' => 'pending', 'paid_at' => null, 'modified_at' => '2026-11-17T18:01:00-05:00'])], [
        'occurred_at' => '2026-11-17T18:01:00-05:00',
        'identity' => ['anonymous_id' => 'anon-1', 'email' => 'Ana@Ejemplo.com'],
        'context' => ['last_touch' => ['utm' => ['source' => 'facebook', 'medium' => 'paid_social'], 'click_ids' => ['fbclid' => 'IwAR']]],
    ]);
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()], ['occurred_at' => '2026-11-17T18:05:00-05:00']);

    $this->postSigned($this->site, $this->secret, [$product, $created, $paid])->assertStatus(202)->assertJsonPath('accepted', 3);
    $this->runWorker();

    inWs($this, function () {
        $order = Order::query()->firstOrFail();
        expect($order->status)->toBe('processing')
            ->and($order->is_paid)->toBeTrue()
            ->and((string) $order->total)->toBe('282000.00')
            ->and((string) $order->total_ws)->toBe('282000.00')
            ->and((string) $order->net_ws)->toBe('270000.00')     // 282.000 − 0 − 12.000 − 0
            ->and((string) $order->cost_ws)->toBe('180000.00')
            ->and($order->paid_at->toIso8601String())->toBe('2026-11-17T23:05:00+00:00')
            ->and($order->items)->toHaveCount(1)
            ->and($order->items->first()->product_id)->toBe(Product::query()->value('id'))
            ->and($order->is_first_order)->toBeTrue()
            ->and($order->order_index)->toBe(1);

        $contact = Contact::query()->firstOrFail();
        expect($order->contact_id)->toBe($contact->id)
            ->and($contact->email)->toBe('ana@ejemplo.com')
            ->and($contact->phone)->toBe('+573001234567')
            ->and($contact->first_name)->toBe('Ana')
            ->and($contact->city)->toBe('Manizales')
            ->and($contact->orders_count)->toBe(1)
            ->and((string) $contact->revenue_total_ws)->toBe('282000.00')
            ->and($contact->lifecycle_stage)->toBe('customer')
            ->and($contact->getRawOriginal('email'))->not->toContain('ana@')
            ->and($contact->email_bidx)->toBe(BlindIndex::email($this->ws->id, 'ana@ejemplo.com'));
        expect(ContactIdentity::query()->pluck('type')->sort()->values()->all())->toBe(['anonymous_id', 'customer_ref', 'email_hash', 'phone_hash']);
    });

    $rows = $this->chRows($this->ws, 'SELECT event, contact_id, channel, click_id_kind, toDecimalString(revenue, 2) AS revenue, city, toJSONString(properties) AS props FROM events WHERE workspace_id = {workspace_id:UInt32} ORDER BY occurred_at, event');
    expect(collect($rows)->pluck('event')->sort()->values()->all())->toBe(['catalog.product_upserted', 'consent.granted', 'order.created', 'order.paid']);
    $paidRow = collect($rows)->firstWhere('event', 'order.paid');
    $createdRow = collect($rows)->firstWhere('event', 'order.created');
    expect($paidRow['revenue'])->toBe('282000.00')
        ->and($paidRow['city'])->toBe('Manizales')
        ->and($createdRow['channel'])->toBe('paid_meta')
        ->and($createdRow['click_id_kind'])->toBe('fbclid')
        ->and($paidRow['contact_id'])->not->toBe('');
    foreach ($rows as $row) {
        expect($row['props'])->not->toContain('ana@ejemplo.com')->not->toContain('Ruiz')->not->toContain('3001234567');
    }
    $map = $this->chRows($this->ws, 'SELECT anonymous_id, contact_id FROM identity_map WHERE workspace_id = {workspace_id:UInt32}');
    expect($map)->toHaveCount(1)->and($map[0]['anonymous_id'])->toBe('anon-1');
});

it('es idempotente: el mismo event_id dos veces se guarda una sola vez', function () {
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()]);
    $this->postSigned($this->site, $this->secret, [$paid, $paid])->assertJsonPath('accepted', 2);
    $this->runWorker();
    $this->postSigned($this->site, $this->secret, [$paid])->assertJsonPath('accepted', 1);
    $this->runWorker();

    expect((int) $this->chRows($this->ws, "SELECT count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'order.paid'")[0]['c'])->toBe(1)
        ->and(inWs($this, fn () => Order::query()->count()))->toBe(1);
});

it('no pisa una foto más nueva con una vieja (llegadas fuera de orden)', function () {
    $completed = $this->envelope($this->site, 'order.completed', ['order_id' => '1234', 'order' => $this->orderSnapshot(['status' => 'completed', 'modified_at' => '2026-11-20T10:00:00-05:00'])]);
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()]);
    $this->postSigned($this->site, $this->secret, [$completed, $paid]);
    $this->runWorker();

    expect(inWs($this, fn () => Order::query()->value('status')))->toBe('completed');
});

it('cuenta la contraentrega como venta solo cuando se cobra (eventos.md §5)', function () {
    $cod = fn (string $status, string $modified) => $this->envelope($this->site, 'order.status_changed', ['order_id' => '1234', 'from' => 'pending', 'to' => $status, 'order' => $this->orderSnapshot([
        'status' => $status, 'paid_at' => null, 'modified_at' => $modified, 'payment_method' => ['id' => 'cod', 'title' => 'Contraentrega', 'is_offline' => true],
    ])]);
    $this->postSigned($this->site, $this->secret, [$cod('processing', '2026-11-17T19:00:00-05:00')]);
    $this->runWorker();
    $order = inWs($this, fn () => Order::query()->firstOrFail());
    expect($order->is_paid)->toBeFalse()->and($order->is_cod)->toBeTrue()->and($order->is_offline_payment)->toBeTrue();

    $this->postSigned($this->site, $this->secret, [$cod('completed', '2026-11-19T19:00:00-05:00')]);
    $this->runWorker();
    $order->refresh();
    expect($order->is_paid)->toBeTrue()->and($order->paid_at)->not->toBeNull();
});

it('lleva los errores de esquema a ingest_rejections sin datos personales', function () {
    $bad = $this->envelope($this->site, 'order.paid', ['order' => ['order_id' => '1', 'billing' => ['email' => 'ana@ejemplo.com']]]);
    $this->postSigned($this->site, $this->secret, [$bad])->assertJsonPath('accepted', 1);
    $this->runWorker();

    $rejection = DB::table('ingest_rejections')->first();
    expect($rejection->reason)->toBe('schema')
        ->and($rejection->event)->toBe('order.paid')
        ->and($rejection->sample)->not->toContain('ana@ejemplo.com')
        ->and((int) $this->chRows($this->ws, 'SELECT count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32}')[0]['c'])->toBe(0);
});

it('la importación histórica no dispara eventos de dominio; el tráfico en vivo sí', function () {
    Event::fake([EventIngested::class]);
    $import = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()], ['source' => ['type' => 'import']]);
    $live = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '1235'])]);
    $this->postSigned($this->site, $this->secret, [$import, $live]);
    $this->runWorker();

    // Solo el pedido en vivo; el importado no dispara nada (los consent.granted del hub son aparte).
    expect(collect(Event::dispatched(EventIngested::class))->filter(fn ($args) => $args[0]->event === 'order.paid')->count())->toBe(1);
    expect(inWs($this, fn () => Order::query()->count()))->toBe(2);
});

it('une por correo a un invitado con el cliente registrado y separa customer_ref por sitio', function () {
    $registered = $this->envelope($this->site, 'customer.registered', ['customer_ref' => 'wc:77', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@ejemplo.com']);
    $guestOrder = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '2000', 'customer_ref' => null, 'billing' => ['phone' => '']])]);
    [$site2, $secret2] = $this->connectedSite($this->ws, 'landing.co');
    $otherStore = $this->envelope($site2, 'customer.registered', ['customer_ref' => 'wc:77', 'first_name' => 'Luis', 'email' => 'luis@ejemplo.com']);

    $this->postSigned($this->site, $this->secret, [$registered, $guestOrder]);
    $this->postSigned($site2, $secret2, [$otherStore]);
    $this->runWorker();

    inWs($this, function () {
        expect(Contact::query()->count())->toBe(2);
        $ana = Contact::query()->where('email_bidx', BlindIndex::email($this->ws->id, 'ana@ejemplo.com'))->firstOrFail();
        expect(Order::query()->value('contact_id'))->toBe($ana->id)->and($ana->fresh()->orders_count)->toBe(1);
    });
});

it('actualiza la salud del sitio con site.heartbeat', function () {
    $hb = $this->envelope($this->site, 'site.heartbeat', ['wp_version' => '6.8.1', 'wc_version' => '9.5.0', 'php_version' => '8.3.6', 'plugin_version' => '0.1.0', 'outbox_pending' => 3, 'last_error' => null]);
    $this->postSigned($this->site, $this->secret, [$hb]);
    $this->runWorker();
    $site = $this->site->fresh();
    expect($site->getAttribute('wc_version'))->toBe('9.5.0')->and($site->health['outbox_pending'])->toBe(3)->and($site->last_event_at)->not->toBeNull();
});

it('un listener que falla no frena la ingesta ni hace reprocesar el lote', function () {
    \Illuminate\Support\Facades\Event::listen(\App\Ingest\Events\EventIngested::class, fn () => throw new RuntimeException('listener roto'));
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()])]);
    $this->runWorker();
    expect(inWs($this, fn () => Order::query()->count()))->toBe(1)
        ->and((int) $this->chRows($this->ws, "SELECT count() AS c FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'order.paid'")[0]['c'])->toBe(1);
});
