<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Job de carritos (hub/CLAUDE.md §5): marca `abandoned` los carritos con ítems y
 * sin actividad durante `abandoned_cart_minutes` (45 por defecto) cuyo dueño es un
 * contacto conocido o identificable por su anónimo, y emite `cart.abandoned`
 * (eventos.md §4.6) con los productos y el enlace de restauración. Los que llevan
 * 30 días quietos pasan a `expired`. Corre cada minuto.
 */
final class DetectAbandonedCarts
{
    private const EXPIRE_DAYS = 30;

    /** Un carrito más viejo que esto al detectarse no se trata como abandono reciente. */
    private const MAX_AGE_HOURS = 72;

    public function __construct(private readonly CurrentWorkspace $current, private readonly HubEvents $hubEvents) {}

    /** @return array{abandoned: int, expired: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $abandoned = 0;
        $expired = Cart::withoutWorkspaceScope()->whereIn('status', ['active', 'abandoned'])
            ->where('last_activity_at', '<', $now->subDays(self::EXPIRE_DAYS))
            ->update(['status' => 'expired', 'updated_at' => $now]);

        $workspaceIds = Cart::withoutWorkspaceScope()->where('status', 'active')->where('items_count', '>', 0)
            ->where('last_activity_at', '<', $now->subMinutes(15))
            ->where('last_activity_at', '>=', $now->subHours(self::MAX_AGE_HOURS))
            ->distinct()->pluck('workspace_id');
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
            try {
                $abandoned += $this->current->run($workspace, fn (): int => $this->forWorkspace($workspace, $now));
            } catch (Throwable $e) {
                report($e);
                Log::error('igr.carts.abandon_failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
            }
        }

        return ['abandoned' => $abandoned, 'expired' => $expired];
    }

    private function forWorkspace(Workspace $workspace, CarbonImmutable $now): int
    {
        $minutes = max(15, (int) $workspace->setting('abandoned_cart_minutes', 45));
        $count = 0;
        $carts = Cart::query()->where('status', 'active')->where('items_count', '>', 0)
            ->where('last_activity_at', '<=', $now->subMinutes($minutes))
            ->where('last_activity_at', '>=', $now->subHours(self::MAX_AGE_HOURS))
            ->orderBy('last_activity_at')->limit(500)->get();
        foreach ($carts as $cart) {
            $contactId = $cart->contact_id ?? ($cart->anonymous_id !== null
                ? ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $cart->anonymous_id)->value('contact_id')
                : null);
            if (! is_string($contactId)) {
                continue; // anónimo puro: no hay a quién escribirle
            }
            // Primero el evento y después la marca (R4): si emitir falla, el carrito sigue activo y se reintenta en la
            // próxima vuelta, en lugar de quedar «abandonado» sin evento (y sin flujo). El event_id sale del carrito y
            // de su última actividad: si dos vueltas lo emiten, ClickHouse, los flujos (trigger_key) y los webhooks lo
            // reciben una sola vez.
            $cart->contact_id = $contactId;
            $site = Site::query()->find($cart->site_id);
            $eventId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'igr:cart.abandoned:'.$cart->id.':'.$cart->last_activity_at->getTimestamp())->toString();
            $this->hubEvents->emit($workspace, 'cart.abandoned', $this->properties($cart, $now), $contactId, $site, $now, $eventId, $cart->anonymous_id);
            $claimed = Cart::query()->whereKey($cart->id)->where('status', 'active')
                ->update(['status' => 'abandoned', 'abandoned_at' => $now, 'contact_id' => $contactId, 'updated_at' => $now]);
            if ($claimed === 1) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array<string, mixed> */
    private function properties(Cart $cart, CarbonImmutable $now): array
    {
        $ids = array_values(array_unique(array_map(fn ($i) => (string) ($i['product_id'] ?? ''), array_filter((array) $cart->items, 'is_array'))));
        $products = Product::query()->where('site_id', $cart->site_id)->whereIn('external_id', $ids)->get()->keyBy('external_id');
        $items = [];
        foreach ((array) $cart->items as $item) {
            if (! is_array($item) || ! isset($item['product_id'])) {
                continue;
            }
            $product = $products->get((string) $item['product_id']);
            $items[] = array_filter([
                'product_id' => (string) $item['product_id'],
                'variation_id' => $item['variation_id'] ?? null,
                'name' => mb_substr((string) ($item['name'] ?? $product?->getAttribute('name') ?? ''), 0, 300) ?: null,
                'qty' => (float) ($item['qty'] ?? 1),
                'price' => isset($item['price']) ? (string) $item['price'] : ($product?->getAttribute('price') !== null ? (string) $product->getAttribute('price') : null),
                'image_url' => $product?->getAttribute('image_url'),
                'permalink' => $product?->getAttribute('permalink'),
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'cart_id' => $cart->publicId(),
            'cart_token' => $cart->token,
            'items' => array_slice($items, 0, 100),
            'subtotal' => number_format((float) $cart->subtotal, 2, '.', ''),
            'currency' => $cart->currency ?? 'COP',
            'items_count' => $cart->items_count,
            'checkout_started' => $cart->checkout_started_at !== null,
            'minutes_inactive' => (int) $cart->last_activity_at->diffInMinutes($now),
            'restore_url' => CartRestoreLink::for($cart),
        ], fn ($v) => $v !== null);
    }
}
