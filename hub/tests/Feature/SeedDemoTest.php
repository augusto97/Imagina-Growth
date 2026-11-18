<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Demo\DemoStoreGenerator;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('genera la tienda demo por el pipeline y concilia al 100%', function () {
    $this->useIngestInfrastructure();

    $this->artisan('igr:seed-demo', ['--customers' => 60, '--orders' => 300, '--process' => true])
        ->expectsOutputToContain('Conciliación: coincide al 100%.')
        ->assertSuccessful();

    $ws = Workspace::query()->where('name', 'Pinturas Demo')->firstOrFail();
    app(CurrentWorkspace::class)->run($ws, function () {
        expect(Order::query()->count())->toBe(300)
            ->and(Order::query()->where('is_cod', true)->count())->toBeGreaterThan(20)
            ->and(Order::query()->distinct()->pluck('status')->sort()->values()->all())->toContain('completed', 'cancelled', 'failed')
            ->and(Contact::query()->count())->toBeGreaterThan(60)
            ->and(Contact::query()->where('orders_count', '>=', 2)->count())->toBeGreaterThan(5);
    });
    expect(DB::table('ingest_rejections')->count())->toBe(0);

    // Es determinista e idempotente: volver a correrlo no duplica nada.
    $this->artisan('igr:seed-demo', ['--customers' => 60, '--orders' => 300, '--process' => true])->assertSuccessful();
    expect(app(CurrentWorkspace::class)->run($ws, fn () => Order::query()->count()))->toBe(300);
});

it('no genera pedidos posteriores a la fecha de corte', function () {
    $until = CarbonImmutable::parse('2026-11-18 09:00:00', 'America/Bogota');
    $gen = new DemoStoreGenerator('st_01JD3QG7Y2V8K6N4M5P9R0S1T2', $until, 1, 3);
    foreach ($gen->events(20, 400) as $event) {
        if ($event['event'] === 'order.created') {
            expect(CarbonImmutable::parse($event['occurred_at'])->lessThanOrEqualTo($until))->toBeTrue();
        }
    }
});
