<?php

namespace Tests\Feature\Reviews\Support;

use App\Domain\Commerce\Models\Order;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Reviews\Models\ReviewRequest;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/** Datos de prueba de reseñas (M15): tienda con dos productos y un pedido de Ana. */
trait ReviewsFixtures
{
    public User $owner;

    public Workspace $ws;

    public Site $site;

    public string $secret;

    public function reviewsSetUp(): void
    {
        $this->useIngestInfrastructure();
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        [$this->site, $this->secret] = $this->connectedSite($this->ws);
        $this->postSigned($this->site, $this->secret, [
            $this->envelope($this->site, 'catalog.category_upserted', ['category_id' => '12', 'name' => 'Pinturas']),
            $this->envelope($this->site, 'catalog.product_upserted', ['product_id' => '88', 'type' => 'simple', 'name' => 'Pintura Azul 1 galón', 'slug' => 'pintura-azul', 'sku' => 'PNT-AZ-1G', 'permalink' => 'https://tienda.co/p/pintura-azul', 'image_url' => 'https://tienda.co/img/88.jpg', 'price' => '150000.00', 'stock_status' => 'instock', 'status' => 'publish', 'category_ids' => ['12'], 'hash' => str_repeat('a', 32)]),
            $this->envelope($this->site, 'catalog.product_upserted', ['product_id' => '91', 'type' => 'simple', 'name' => 'Rodillo profesional', 'slug' => 'rodillo', 'sku' => 'ROD-01', 'price' => '30000.00', 'stock_status' => 'instock', 'status' => 'publish', 'category_ids' => ['15'], 'hash' => str_repeat('b', 32)]),
        ]);
        $this->runWorker();
    }

    /** Pedido pagado de Ana (88 y, opcionalmente, 91); con `completed` también se entrega. */
    public function reviewsOrder(string $orderId = '1234', bool $completed = true, bool $withRoller = true): Order
    {
        $items = [[
            'item_id' => '55', 'product_id' => '88', 'variation_id' => null, 'sku' => 'PNT-AZ-1G', 'name' => 'Pintura Azul 1 galón',
            'category_ids' => ['12'], 'qty' => 2, 'unit_price' => '150000.00', 'line_total' => '300000.00',
        ]];
        if ($withRoller) {
            $items[] = ['item_id' => '56', 'product_id' => '91', 'variation_id' => null, 'sku' => 'ROD-01', 'name' => 'Rodillo profesional', 'category_ids' => ['15'], 'qty' => 1, 'unit_price' => '30000.00', 'line_total' => '30000.00'];
        }
        $snapshot = $this->orderSnapshot(['order_id' => $orderId, 'number' => $orderId, 'coupons' => []]);
        $snapshot['items'] = $items;
        $events = [$this->envelope($this->site, 'order.paid', ['order' => $snapshot])];
        if ($completed) {
            $snapshot['status'] = 'completed';
            $events[] = $this->envelope($this->site, 'order.completed', ['order_id' => $orderId, 'order' => $snapshot]);
        }
        $this->postSigned($this->site, $this->secret, $events);
        $this->runWorker();

        return $this->reviewsInWs(fn () => Order::query()->where('external_id', $orderId)->firstOrFail());
    }

    /** @param  array<string, mixed>  $request */
    public function reviewsEnable(array $request = [], array $rest = []): void
    {
        $d = ReviewSettings::defaults();
        $settings = array_replace_recursive($d, ['request' => ['enabled' => true, 'channels' => ['email']] + $request], $rest);
        if (isset($request['channels'])) {
            $settings['request']['channels'] = $request['channels'];
        }
        if (array_key_exists('category_days', $request)) {
            $settings['request']['category_days'] = $request['category_days'];
        }
        ReviewSettings::save($this->ws, $settings);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function reviewsInWs(callable $fn): mixed
    {
        return app(CurrentWorkspace::class)->run($this->ws, $fn);
    }

    /** Solicitud enviada del pedido, lista para abrir el formulario. */
    public function reviewsSentRequest(string $orderId = '1234'): ReviewRequest
    {
        $this->reviewsEnable();
        $order = $this->reviewsOrder($orderId);

        return $this->reviewsInWs(function () use ($order) {
            $request = ReviewRequest::query()->where('order_id', $order->id)->firstOrFail();
            $request->forceFill(['status' => 'sent', 'channel' => 'email', 'sent_at' => now(), 'expires_at' => CarbonImmutable::now()->addDays(60)])->save();

            return $request;
        });
    }

    public function reviewsFormPath(ReviewRequest $request): string
    {
        return '/r/'.ReviewToken::forRequest($request);
    }

    /**
     * Libro de puntos de prueba: registra las llamadas, es idempotente por clave y
     * usa `rules` cuando se pide con `points <= 0` (como LoyaltyLedger::rulePoints).
     * Es un mock de Mockery para no romperse si el contrato gana métodos.
     *
     * @param  array<string, int>  $rules
     */
    public function reviewsLedgerSpy(bool $active = true, array $rules = ['review' => 50, 'review_media' => 150]): \stdClass
    {
        $spy = new \stdClass;
        $spy->earned = [];
        $spy->settled = [];
        $mock = \Mockery::mock(LoyaltyLedger::class);
        $mock->shouldReceive('isActive')->andReturn($active);
        $mock->shouldReceive('rulePoints')->andReturnUsing(fn (string $reason) => $active ? ($rules[$reason] ?? 0) : 0);
        $mock->shouldReceive('earnPoints')->andReturnUsing(function (string $contactId, int $points, string $reason, string $key, ?string $refType = null, ?string $refId = null, bool $pending = false) use ($spy, $rules, $active) {
            $points = $points > 0 ? $points : ($rules[$reason] ?? 0);
            if (! $active || $points <= 0) {
                return null;
            }
            $spy->earned[$key] ??= ['points' => $points, 'reason' => $reason, 'pending' => $pending, 'ref' => $refId];

            return 'lg_'.md5($key);
        });
        $mock->shouldReceive('settle')->andReturnUsing(function (string $refType, string $refId, bool $confirm) use ($spy) {
            $spy->settled[] = ['ref' => $refId, 'confirm' => $confirm];
        });
        $mock->shouldIgnoreMissing();
        app()->instance(LoyaltyLedger::class, $mock);

        return $spy;
    }
}
