<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Flows\Engine\RunState;
use Illuminate\Support\Facades\DB;

/**
 * Variables de plantilla (RenderContext::VARIABLES) que salen del run: el
 * `carrito` del disparador (con el enlace para restaurarlo y el cupón si ya se
 * generó), el `pedido`, el `producto` visto y el `cupon`. `contacto`, `tienda` y
 * `enlaces` los pone el renderizador o el despachador.
 */
final class TemplateContext
{
    /** @return array<string, mixed> */
    public static function build(RunState $state): array
    {
        $context = (array) ($state->run->context ?? []);
        $props = (array) ($context['props'] ?? []);
        $vars = [];
        $coupon = is_array($context['cupon'] ?? null) ? $context['cupon'] : null;
        if ($coupon !== null) {
            $vars['cupon'] = $coupon;
        }

        if (isset($props['cart_id']) || isset($props['cart_token'])) {
            $vars['carrito'] = self::cart($props, $coupon['codigo'] ?? null);
        }
        $order = is_array($props['order'] ?? null) ? $props['order'] : null;
        if ($order !== null || isset($props['order_id'])) {
            $vars['pedido'] = array_filter([
                'numero' => (string) ($order['number'] ?? $order['order_id'] ?? $props['order_id'] ?? ''),
                'total' => (string) ($order['totals']['total'] ?? $props['total'] ?? ''),
                'moneda' => (string) ($order['currency'] ?? $props['currency'] ?? ''),
                'estado' => (string) ($order['status'] ?? ''),
                'metodo_pago' => (string) ($order['payment_method']['title'] ?? $order['payment_method']['id'] ?? $props['payment_method'] ?? ''),
                'url_pago' => (string) ($props['pay_url'] ?? $order['pay_url'] ?? ''),
            ], fn ($v) => $v !== '');
        }
        if (isset($props['product_id'])) {
            $vars['producto'] = self::product($state->workspace->id, (string) $props['product_id'], $props);
        }

        return $vars;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function cart(array $props, ?string $couponCode): array
    {
        $items = [];
        foreach ((array) ($props['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = array_filter([
                'nombre' => (string) ($item['name'] ?? ''),
                'cantidad' => $item['qty'] ?? 1,
                'precio' => (string) ($item['price'] ?? ''),
                'imagen' => (string) ($item['image_url'] ?? ''),
                'url' => (string) ($item['permalink'] ?? ''),
            ], fn ($v) => $v !== '');
        }
        $url = (string) ($props['restore_url'] ?? '');
        $cart = is_string($props['cart_id'] ?? null) ? Cart::findByPublicId($props['cart_id']) : null;
        if ($cart !== null && ($couponCode !== null || $url === '')) {
            $url = CartRestoreLink::for($cart, $couponCode) ?? $url;
        }

        return array_filter([
            'items' => $items,
            'subtotal' => (string) ($props['subtotal'] ?? ''),
            'moneda' => (string) ($props['currency'] ?? ''),
            'cantidad' => $props['items_count'] ?? count($items),
            'url_restaurar' => $url,
        ], fn ($v) => $v !== '' && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function product(string $workspaceId, string $externalId, array $props): array
    {
        $row = DB::table('products')->where('workspace_id', $workspaceId)->where('external_id', $externalId)
            ->first(['name', 'price', 'permalink', 'image_url']);

        return array_filter([
            'nombre' => (string) ($row->name ?? $props['name'] ?? ''),
            'precio' => (string) ($row->price ?? $props['price'] ?? ''),
            'url' => (string) ($row->permalink ?? ''),
            'imagen' => (string) ($row->image_url ?? ''),
        ], fn ($v) => $v !== '');
    }
}
