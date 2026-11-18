<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Support\Holidays\Holidays;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Genera una tienda colombiana creíble como eventos del contrato v1
 * (source.type = import): catálogo, clientes registrados e invitados, y
 * pedidos de 24 meses con estacionalidad (prima, Día de la Madre, Black
 * Friday, diciembre), menos ventas en domingos y festivos, pagos PSE, Nequi,
 * contraentrega y transferencia, cancelaciones y reembolsos.
 *
 * Es determinista con la semilla. Devuelve también la conciliación por
 * estado, igual a la que calcula el plugin (api-connector.md §5.3).
 */
final class DemoStoreGenerator
{
    /** Mismo espacio de nombres que el connector (api-connector.md §1.1). */
    public const UUID_NAMESPACE = '4a9293ae-2f42-5b83-905c-3b8de1f000cb';

    /** @var list<array<string, mixed>> */
    private array $products = [];

    private ?DemoBrowsingGenerator $browsing = null;

    /** @var array{orders_by_status: array<string, array{count: int, total: string}>, refunds: array{count: int, total: string}} */
    private array $reconciliation = ['orders_by_status' => [], 'refunds' => ['count' => 0, 'total' => '0.00']];

    public function __construct(
        private readonly string $siteId,
        private readonly CarbonImmutable $until,
        private readonly int $months = 24,
        private readonly int $seed = 2026,
    ) {}

    /**
     * Activa la navegación demo: cada pedido dentro de la ventana de
     * navegación tiene su sesión de compra con el mismo visitante y sesión.
     */
    public function withBrowsing(int $months = 12, float $conversionRate = 0.016): DemoBrowsingGenerator
    {
        $this->browsing = new DemoBrowsingGenerator($this->siteId, $this->until, [], $months, $this->seed, $conversionRate);

        return $this->browsing;
    }

    /** @return list<array<string, mixed>> catálogo generado (después de recorrer events()) */
    public function products(): array
    {
        return $this->products;
    }

    /**
     * @return \Generator<int, array<string, mixed>> sobres de evento
     */
    public function events(int $customers, int $orders): \Generator
    {
        mt_srand($this->seed);
        $from = $this->until->subMonths($this->months)->startOfDay();

        yield from $this->catalog($from);
        $this->browsing?->withProducts($this->products);

        // Clientes registrados con una propensión de compra muy desigual (la mayoría compra una vez).
        $people = [];
        for ($i = 1; $i <= $customers; $i++) {
            $people[] = $this->person($i, true) + ['weight' => exp($this->gauss() * 1.1)];
        }
        $weights = array_column($people, 'weight');
        $registered = [];

        $dates = $this->orderDates($from, $orders);
        $orderId = 1000;
        $refundId = 900000;
        $guestSeq = 1;
        foreach ($dates as $createdAt) {
            $orderId++;
            if (mt_rand(1, 100) <= 27) {
                $buyer = $this->person(100000 + $guestSeq++, false);
            } else {
                $buyer = $people[$this->pick($weights)];
                if (! isset($registered[$buyer['ref']])) {
                    $registered[$buyer['ref']] = true;
                    yield $this->envelope('customer.registered', 'customer:'.substr($buyer['ref'], 3).':registered', $createdAt->subMinutes(mt_rand(5, 600)), [
                        'customer_ref' => $buyer['ref'],
                        'first_name' => $buyer['first_name'], 'last_name' => $buyer['last_name'],
                        'email' => $buyer['email'], 'phone' => $buyer['phone'], 'marketing_optin' => null,
                        'billing' => ['city' => $buyer['city'], 'state' => $buyer['state'], 'country' => 'CO'],
                    ]);
                }
            }

            [$order, $refunds] = $this->order((string) $orderId, $buyer, $createdAt, $refundId);
            $refundId += count($refunds);

            // Con navegación: el pedido lleva el visitante, la sesión y el último contacto (cookies del connector).
            $session = $this->browsing?->covers($createdAt) ? $this->browsing->purchaseSession($buyer, $order, $createdAt) : null;

            yield $this->envelope('order.created', "order:{$orderId}:created", $createdAt, ['order' => $order], $session);
            if ($order['paid_at'] !== null) {
                yield $this->envelope('order.paid', "order:{$orderId}:paid", CarbonImmutable::parse($order['paid_at']), ['order' => $order], $session);
            }
            foreach ($refunds as $refund) {
                yield $this->envelope('order.refunded', 'refund:'.$refund['refund_id'], CarbonImmutable::parse($refund['refunded_at']), $refund + ['order' => $order]);
            }

            $bucket = &$this->reconciliation['orders_by_status'][$order['status']];
            $bucket ??= ['count' => 0, 'total' => '0.00'];
            $bucket['count']++;
            $bucket['total'] = Decimal::add($bucket['total'], $order['totals']['total']);
            unset($bucket);
            if ($order['totals']['refunded'] !== '0.00') {
                $this->reconciliation['refunds']['count'] += count($refunds);
                $this->reconciliation['refunds']['total'] = Decimal::add($this->reconciliation['refunds']['total'], $order['totals']['refunded']);
            }
        }
    }

    /** @return array{orders_by_status: array<string, array{count: int, total: string}>, refunds: array{count: int, total: string}} */
    public function reconciliation(): array
    {
        ksort($this->reconciliation['orders_by_status']);

        return $this->reconciliation;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function catalog(CarbonImmutable $from): \Generator
    {
        $catId = 10;
        $productId = 100;
        foreach (ColombiaData::CATALOG as $category => $def) {
            $catId++;
            $slug = $this->slug($category);
            yield $this->envelope('catalog.category_upserted', "category:{$catId}:".substr(md5($category), 0, 16), $from, [
                'category_id' => (string) $catId, 'parent_id' => null, 'name' => $category, 'slug' => $slug,
            ]);
            foreach ($def['items'] as $item) {
                foreach (array_slice($def['brands'], 0, mt_rand(1, 2)) as $brand) {
                    $productId++;
                    $price = (int) (round(mt_rand($def['min'], $def['max']) / 100) * 100);
                    $product = [
                        'product_id' => (string) $productId, 'parent_id' => null, 'type' => 'simple',
                        'sku' => strtoupper(substr($this->slug($brand), 0, 3)).'-'.$productId,
                        'name' => "{$item} {$brand}", 'slug' => $this->slug("{$item} {$brand}"),
                        'permalink' => 'https://demo.tienda.co/producto/'.$this->slug("{$item} {$brand}").'/',
                        'image_url' => null, 'category_ids' => [(string) $catId], 'brand' => $brand,
                        'price' => $price.'.00', 'regular_price' => $price.'.00', 'sale_price' => null,
                        'stock_status' => mt_rand(1, 100) <= 92 ? 'instock' : 'outofstock', 'stock_qty' => mt_rand(0, 120),
                        'rating_avg' => round(mt_rand(36, 50) / 10, 1), 'rating_count' => mt_rand(0, 80),
                        'status' => 'publish', 'cost' => (int) round($price * 0.62).'.00', 'currency' => 'COP',
                        'popularity' => exp($this->gauss() * 0.9),
                    ];
                    $product['hash'] = substr(hash('sha256', json_encode($product) ?: ''), 0, 32);
                    $this->products[] = $product + ['category_slug' => $slug, 'category_name' => $category];
                    $payload = $product;
                    unset($payload['popularity']);
                    yield $this->envelope('catalog.product_upserted', "product:{$productId}:{$product['hash']}", $from, $payload);
                }
            }
        }
    }

    /**
     * @return list<CarbonImmutable> fechas de pedido ordenadas, con estacionalidad
     */
    private function orderDates(CarbonImmutable $from, int $count): array
    {
        $days = [];
        $weights = [];
        $total = (int) $from->diffInDays($this->until);
        for ($d = 0, $day = $from; $d <= $total; $d++, $day = $day->addDay()) {
            $w = 1 + 0.45 * ($d / max(1, $total));                           // crecimiento del negocio
            $w *= [0.55, 1.1, 1.05, 1.0, 1.0, 1.05, 0.9][$day->dayOfWeek];   // domingo flojo
            $w *= match ($day->month) {
                12 => 1.45, 6 => 1.2, 5 => 1.15, 11 => 1.2, 1 => 0.8, default => 1.0
            };
            if (Holidays::isHoliday('CO', $day)) {
                $w *= 0.6;
            }
            if ($day->month === 11 && $day->isFriday() && $day->day >= 23 && $day->day <= 29) {
                $w *= 3.0;                                                    // Black Friday
            }
            if ($day->month === 5 && $day->day >= 3 && $day->day <= 10) {
                $w *= 1.6;                                                    // antes del Día de la Madre
            }
            $days[] = $day;
            $weights[] = $w;
        }
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $day = $days[$this->pick($weights)];
            $hour = $this->pick([1, 1, 1, 1, 1, 1, 2, 4, 6, 7, 8, 8, 8, 7, 7, 8, 8, 8, 9, 10, 10, 8, 5, 2]);
            $at = $day->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
            // Nunca después del corte: el plugin no ve pedidos del futuro.
            $dates[] = $at->greaterThan($this->until) ? $this->until->subMinutes(mt_rand(10, 600)) : $at;
        }
        usort($dates, fn ($a, $b) => $a <=> $b);

        return $dates;
    }

    /**
     * @param  array<string, mixed>  $buyer
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function order(string $id, array $buyer, CarbonImmutable $createdAt, int $refundId): array
    {
        $gatewayIds = array_keys(ColombiaData::GATEWAYS);
        $gateway = $gatewayIds[$this->pick(array_column(ColombiaData::GATEWAYS, 1))];
        [$gatewayTitle, , $offline] = ColombiaData::GATEWAYS[$gateway];

        $items = [];
        $subtotal = 0;
        $lines = $this->pick([0, 55, 25, 12, 8]);
        $popularity = array_column($this->products, 'popularity');
        for ($l = 0; $l < max(1, $lines); $l++) {
            $p = $this->products[$this->pick($popularity)];
            $qty = $this->pick([0, 70, 20, 7, 3]) ?: 1;
            $line = (int) $p['price'] * $qty;
            $subtotal += $line;
            $items[] = [
                'item_id' => $id.'-'.($l + 1), 'product_id' => $p['product_id'], 'variation_id' => null, 'sku' => $p['sku'],
                'name' => $p['name'], 'category_ids' => $p['category_ids'], 'brand' => $p['brand'], 'qty' => $qty,
                'unit_price' => $p['price'], 'line_total' => $line.'.00', 'cost' => $p['cost'],
            ];
        }
        $coupons = [];
        $discount = 0;
        if (mt_rand(1, 100) <= 18) {
            $isBlackFriday = $createdAt->month === 11 && $createdAt->day >= 20;
            $coupons[] = $isBlackFriday ? 'BLACKFRIDAY20' : (mt_rand(0, 1) === 1 ? 'BIENVENIDA10' : 'PRIMA15');
            $pct = $isBlackFriday ? 20 : (str_starts_with($coupons[0], 'PRIMA') ? 15 : 10);
            $discount = (int) round($subtotal * $pct / 100 / 100) * 100;
        }
        $shipping = $subtotal - $discount >= 250000 ? 0 : [9900, 12000, 15000, 18000][mt_rand(0, 3)];
        $total = $subtotal - $discount + $shipping;

        // Estado actual del pedido según el medio de pago.
        $roll = mt_rand(1, 1000);
        $recent = $createdAt->greaterThan($this->until->subDays(4));
        if ($gateway === 'cod') {
            $status = $recent ? ($roll <= 600 ? 'processing' : 'pending') : ($roll <= 840 ? 'completed' : 'cancelled');
        } elseif ($gateway === 'bacs') {
            $status = $roll <= 780 ? ($recent ? 'processing' : 'completed') : ($roll <= 860 ? 'on-hold' : 'cancelled');
        } else {
            $status = match (true) {
                $roll <= 60 => 'failed',
                $roll <= 100 => 'pending',
                $roll <= 130 => 'cancelled',
                $roll <= 160 => 'refunded',
                $recent || $roll <= 220 => 'processing',
                default => 'completed',
            };
        }

        $paidAt = null;
        $modifiedAt = $createdAt->addMinutes(mt_rand(1, 30));
        if (in_array($status, ['processing', 'completed', 'refunded'], true) && ! ($gateway === 'cod' && $status === 'processing')) {
            $paidAt = $gateway === 'cod' ? $createdAt->addDays(mt_rand(2, 5))->setTime(mt_rand(9, 17), mt_rand(0, 59))
                : ($gateway === 'bacs' ? $createdAt->addHours(mt_rand(2, 30)) : $createdAt->addMinutes(mt_rand(1, 8)));
            $modifiedAt = $status === 'processing' ? $paidAt : $paidAt->addDays(mt_rand(1, 6));
        } elseif (in_array($status, ['cancelled', 'failed'], true)) {
            $modifiedAt = $createdAt->addHours(mt_rand(1, 72));
        }
        $paidAt = $paidAt !== null && $paidAt->greaterThan($this->until) ? $this->until->subMinutes(5) : $paidAt;
        $modifiedAt = $modifiedAt->greaterThan($this->until) ? $this->until->subMinutes(1) : $modifiedAt;

        $refunds = [];
        $refunded = 0;
        if ($status === 'refunded' || ($status === 'completed' && mt_rand(1, 100) <= 2)) {
            $amount = $status === 'refunded' ? $total : (int) $items[0]['unit_price'];
            $refundedAt = ($paidAt ?? $createdAt)->addDays(mt_rand(1, 10));
            $refundedAt = $refundedAt->greaterThan($this->until) ? $this->until->subMinutes(2) : $refundedAt;
            $modifiedAt = $refundedAt->greaterThan($modifiedAt) ? $refundedAt : $modifiedAt;
            $refunded = $amount;
            $refunds[] = [
                'order_id' => $id, 'refund_id' => (string) $refundId, 'amount' => $amount.'.00', 'is_partial' => $status !== 'refunded',
                'reason' => $status === 'refunded' ? 'Producto no disponible' : 'Producto con daño', 'refunded_at' => $refundedAt->toIso8601String(),
                'items' => [['item_id' => $items[0]['item_id'], 'product_id' => $items[0]['product_id'], 'qty' => 1, 'amount' => $items[0]['unit_price']]],
            ];
        }

        $order = [
            'order_id' => $id, 'number' => $id, 'status' => $status,
            'created_at' => $createdAt->toIso8601String(), 'paid_at' => $paidAt?->toIso8601String(), 'modified_at' => $modifiedAt->toIso8601String(),
            'created_via' => 'checkout', 'currency' => 'COP',
            'totals' => ['subtotal' => $subtotal.'.00', 'discount' => $discount.'.00', 'shipping' => $shipping.'.00', 'tax' => '0.00', 'total' => $total.'.00', 'refunded' => $refunded.'.00'],
            'payment_method' => ['id' => $gateway, 'title' => $gatewayTitle, 'is_offline' => $offline],
            'shipping_method' => $shipping === 0 ? ['id' => 'free_shipping', 'title' => 'Envío gratis'] : ['id' => 'flat_rate', 'title' => 'Envío nacional'],
            'coupons' => $coupons, 'items' => $items,
            'billing' => ['first_name' => $buyer['first_name'], 'last_name' => $buyer['last_name'], 'email' => $buyer['email'], 'phone' => $buyer['phone_local'], 'city' => $buyer['city'], 'state' => $buyer['state'], 'country' => 'CO', 'postcode' => ''],
            'shipping' => ['city' => $buyer['city'], 'state' => $buyer['state'], 'country' => 'CO'],
            'customer_ref' => $buyer['ref'],
            'wc_attribution' => null, 'consents' => null, 'meta' => new \stdClass,
        ];

        return [$order, $refunds];
    }

    /** @return array<string, mixed> */
    private function person(int $n, bool $registered): array
    {
        $first = ColombiaData::FIRST_NAMES[mt_rand(0, count(ColombiaData::FIRST_NAMES) - 1)];
        $last = ColombiaData::LAST_NAMES[mt_rand(0, count(ColombiaData::LAST_NAMES) - 1)];
        $city = ColombiaData::CITIES[$this->pick(array_column(ColombiaData::CITIES, 2))];
        $mobile = '3'.[0, 1, 2, 5][mt_rand(0, 3)].mt_rand(0, 9).str_pad((string) mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);

        return [
            'ref' => $registered ? 'wc:'.$n : null,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($this->slug($first).'.'.$this->slug($last)).$n.'@correo.test',
            'phone' => '+57'.$mobile, 'phone_local' => $mobile,
            'city' => $city[0], 'state' => $city[1],
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array{identity: array<string, string>, context: array<string, mixed>}|null  $session  sesión de navegación demo del pedido
     * @return array<string, mixed>
     */
    private function envelope(string $event, string $key, CarbonImmutable $at, array $properties, ?array $session = null): array
    {
        return [
            'schema_version' => 1,
            'event_id' => Uuid::uuid5(self::UUID_NAMESPACE, $this->siteId.':'.$key)->toString(),
            'event' => $event,
            'occurred_at' => $at->toIso8601String(),
            'source' => ['type' => 'import', 'platform' => 'woocommerce', 'site_id' => $this->siteId, 'sdk' => 'hub/seed-demo'],
            'identity' => $session['identity'] ?? new \stdClass,
            'context' => $session['context'] ?? ['currency' => 'COP', 'locale' => 'es-CO'],
            'properties' => $properties,
        ];
    }

    /** @param list<float|int> $weights */
    private function pick(array $weights): int
    {
        $sum = array_sum($weights);
        $r = mt_rand() / mt_getrandmax() * $sum;
        foreach ($weights as $i => $w) {
            $r -= $w;
            if ($r <= 0) {
                return $i;
            }
        }

        return count($weights) - 1;
    }

    private function gauss(): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $text))), '-');
    }
}
