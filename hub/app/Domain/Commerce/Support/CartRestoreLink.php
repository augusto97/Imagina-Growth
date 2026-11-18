<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Identity\SiteToken;
use App\Domain\Sites\Models\Site;

/**
 * Enlace que reconstruye el carrito en el sitio (api-connector.md §6.1):
 * `https://tienda.co/?igr_cart=<payload>.<firma>`. El plugin lo verifica con el
 * secreto del sitio, sin llamar al hub. Lo usan los flujos de carrito abandonado
 * y reposición; el despachador lo envuelve después en un enlace rastreado.
 */
final class CartRestoreLink
{
    public static function for(Cart $cart, ?string $coupon = null, int $ttlDays = 7, string $mode = 'replace'): ?string
    {
        $site = Site::query()->find($cart->site_id);
        $items = [];
        foreach ((array) $cart->items as $item) {
            if (! is_array($item) || ! isset($item['product_id'])) {
                continue;
            }
            $items[] = [
                'product_id' => (string) $item['product_id'],
                'variation_id' => isset($item['variation_id']) && $item['variation_id'] !== '' ? (string) $item['variation_id'] : null,
                'qty' => (float) ($item['qty'] ?? 1),
            ];
        }
        if ($site === null || $items === [] || $site->url === null) {
            return null;
        }
        $token = SiteToken::sign($site->secret, array_filter([
            'site' => $site->publicId(),
            'cart' => $cart->publicId(),
            'items' => $items,
            'coupon' => $coupon,
            'mode' => $mode,
            'exp' => now()->addDays(max(1, $ttlDays))->getTimestamp(),
        ], fn ($v) => $v !== null));
        $base = rtrim((string) $site->url, '/').'/';

        return $base.'?igr_cart='.$token;
    }
}
