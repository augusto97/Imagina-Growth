<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Projectors;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Ids\PrefixedId;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

/**
 * Proyección de carritos (hub/CLAUDE.md §5): `cart.*`, `checkout.started` y
 * `coupon.applied` hacen upsert en `carts` por (site_id, cart_token). Al llegar
 * el pedido, el carrito queda `recovered` si se había abandonado o `converted`
 * si no, y los cupones del hub que usó quedan `redeemed`.
 *
 * Los eventos llegan desordenados (el outbox del plugin, el navegador): uno más
 * viejo que la última actividad no pisa los ítems, solo completa lo que falte.
 */
final class CartProjector
{
    public const EVENTS = ['cart.item_added', 'cart.item_removed', 'cart.updated', 'checkout.started', 'coupon.applied'];

    /** Un pedido sin cart_token (plugin v2) cierra el carrito abandonado del contacto de los últimos días. */
    private const RECOVERY_DAYS = 7;

    public function __construct(private readonly FxConverter $fx) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function project(Workspace $workspace, Site $site, array $envelope, ?string $contactId): ?Cart
    {
        $props = is_array($envelope['properties'] ?? null) ? $envelope['properties'] : [];
        $token = isset($props['cart_token']) && is_string($props['cart_token']) ? $props['cart_token'] : null;
        if ($token === null || $token === '') {
            return null;
        }
        $event = (string) $envelope['event'];
        $at = CarbonImmutable::parse((string) $envelope['occurred_at'])->utc();
        $anonymous = $envelope['identity']['anonymous_id'] ?? null;

        $cart = Cart::query()->where('site_id', $site->id)->where('token', $token)->lockForUpdate()->first()
            ?? new Cart(['workspace_id' => $workspace->id, 'site_id' => $site->id, 'token' => $token, 'status' => 'active', 'last_activity_at' => $at, 'items_count' => 0, 'subtotal' => '0', 'subtotal_ws' => '0']);
        $newer = ! $cart->exists || $at->greaterThanOrEqualTo($cart->last_activity_at);

        if ($cart->contact_id === null && $contactId !== null) {
            $cart->contact_id = $contactId;
        }
        if ($cart->anonymous_id === null && is_string($anonymous) && $anonymous !== '') {
            $cart->anonymous_id = mb_substr($anonymous, 0, 64);
        }
        if ($newer) {
            $cart->last_activity_at = $at;
            // Volvió a moverse un carrito abandonado: vuelve a activo (los flujos ya en curso siguen).
            if ($cart->status === 'abandoned' || $cart->status === 'expired') {
                $cart->status = 'active';
            }
        }

        switch ($event) {
            case 'cart.updated':
                if ($newer || $cart->items === null) {
                    $items = [];
                    foreach ((array) ($props['items'] ?? []) as $item) {
                        if (is_array($item) && isset($item['product_id'])) {
                            $items[] = array_filter([
                                'product_id' => (string) $item['product_id'],
                                'variation_id' => isset($item['variation_id']) ? (string) $item['variation_id'] : null,
                                'sku' => $item['sku'] ?? null,
                                'name' => isset($item['name']) ? mb_substr((string) $item['name'], 0, 300) : null,
                                'qty' => (float) ($item['qty'] ?? 0),
                                'price' => $item['price'] ?? null,
                                'line_total' => $item['line_total'] ?? null,
                            ], fn ($v) => $v !== null);
                        }
                    }
                    $cart->items = $items;
                    $cart->items_count = (int) round(array_sum(array_map(fn (array $i) => (float) $i['qty'], $items)));
                    $this->subtotal($cart, $workspace, (string) ($props['subtotal'] ?? '0'), $props['currency'] ?? null, $at);
                    $cart->coupons = array_values(array_map('strval', (array) ($props['coupons'] ?? [])));
                }
                // v3: carrito armado desde un enlace de restauración (api-connector.md §6.1).
                if ($cart->restored_from_id === null && isset($props['restored_from']) && is_string($props['restored_from'])) {
                    $original = Cart::query()->where('site_id', $site->id)->whereKey(PrefixedId::decode(Cart::ID_PREFIX, $props['restored_from']) ?? '-')->first();
                    $cart->restored_from_id = $original?->id;
                }
                break;
            case 'cart.item_added':
                if ($newer) {
                    // Hasta el próximo cart.updated (con throttle de 30 s), se suma el ítem a lo conocido.
                    $items = (array) $cart->items;
                    $items[] = array_filter([
                        'product_id' => (string) ($props['product_id'] ?? ''),
                        'variation_id' => isset($props['variation_id']) ? (string) $props['variation_id'] : null,
                        'qty' => (float) ($props['qty'] ?? 1),
                        'price' => $props['price'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '');
                    $cart->items = $items;
                    $cart->items_count += (int) round((float) ($props['qty'] ?? 1));
                    if (isset($props['cart_total'])) {
                        $this->subtotal($cart, $workspace, (string) $props['cart_total'], $props['currency'] ?? null, $at);
                    }
                }
                break;
            case 'cart.item_removed':
                if ($newer) {
                    $remove = (string) ($props['product_id'] ?? '');
                    $cart->items = array_values(array_filter((array) $cart->items, fn ($i) => ! is_array($i) || (string) ($i['product_id'] ?? '') !== $remove));
                    $cart->items_count = (int) round(array_sum(array_map(fn ($i) => is_array($i) ? (float) ($i['qty'] ?? 0) : 0.0, (array) $cart->items)));
                }
                break;
            case 'checkout.started':
                $cart->checkout_started_at ??= $at;
                if (isset($props['subtotal']) && $newer) {
                    $this->subtotal($cart, $workspace, (string) $props['subtotal'], $props['currency'] ?? null, $at);
                }
                break;
            case 'coupon.applied':
                $cart->coupons = array_values(array_unique([...(array) $cart->coupons, (string) ($props['code'] ?? '')]));
                break;
        }

        $cart->save();

        return $cart;
    }

    /**
     * El pedido cierra su carrito y redime los cupones del hub.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function onOrder(Site $site, Order $order, array $envelope): void
    {
        $snapshot = $envelope['properties']['order'] ?? null;
        if (! is_array($snapshot)) {
            return;
        }
        $event = (string) $envelope['event'];
        if ($event === 'order.created' || $event === 'order.paid') {
            $this->closeCart($site, $order, isset($snapshot['cart_token']) && is_string($snapshot['cart_token']) ? $snapshot['cart_token'] : null);
        }
        if ($order->is_paid) {
            $this->redeemCoupons($site, $order, $snapshot);
        }
    }

    private function closeCart(Site $site, Order $order, ?string $token): void
    {
        $cart = null;
        if ($token !== null && $token !== '') {
            $cart = Cart::query()->where('site_id', $site->id)->where('token', $token)->first();
        }
        if ($cart === null && $order->contact_id !== null) {
            // Plugin v2 (sin cart_token): el carrito abierto o abandonado más reciente del contacto en el sitio.
            $cart = Cart::query()->where('site_id', $site->id)->where('contact_id', $order->contact_id)
                ->whereIn('status', ['active', 'abandoned'])
                ->where('last_activity_at', '>=', CarbonImmutable::parse((string) $order->created_at)->subDays(self::RECOVERY_DAYS))
                ->orderByDesc('last_activity_at')->first();
        }
        if ($cart === null || $cart->order_id !== null) {
            return;
        }
        $recovered = $cart->status === 'abandoned' || $cart->abandoned_at !== null;
        $cart->forceFill([
            'status' => $recovered ? 'recovered' : 'converted',
            'order_id' => $order->id,
            'recovered_order_id' => $recovered ? $order->id : null,
            'contact_id' => $cart->contact_id ?? $order->contact_id,
        ])->save();
        // El carrito nuevo salió de un enlace de recuperación: el abandonado original queda recuperado.
        if ($cart->restored_from_id !== null) {
            Cart::query()->whereKey($cart->restored_from_id)->whereNull('order_id')->update([
                'status' => 'recovered', 'order_id' => $order->id, 'recovered_order_id' => $order->id, 'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function redeemCoupons(Site $site, Order $order, array $snapshot): void
    {
        $codes = [];
        foreach ((array) ($snapshot['coupons'] ?? []) as $coupon) {
            $code = is_array($coupon) ? ($coupon['code'] ?? null) : $coupon;
            if (is_string($code) && $code !== '') {
                $codes[] = strtoupper($code);
            }
        }
        if ($codes === []) {
            return;
        }
        CouponIssued::query()->where('site_id', $site->id)->whereIn('code', $codes)->whereNull('redeemed_order_id')
            ->update(['status' => 'redeemed', 'redeemed_order_id' => $order->id, 'redeemed_at' => $order->paid_at ?? now(), 'updated_at' => now()]);
    }

    private function subtotal(Cart $cart, Workspace $workspace, string $amount, mixed $currency, CarbonImmutable $at): void
    {
        $currency = is_string($currency) && strlen($currency) === 3 ? strtoupper($currency) : ($cart->currency ?? $workspace->currency);
        $cart->subtotal = $amount;
        $cart->currency = $currency;
        $rate = $this->fx->rate($currency, $workspace->currency, $at);
        $cart->subtotal_ws = $rate === null ? $amount : Decimal::mul($amount, $rate, 2);
    }
}
