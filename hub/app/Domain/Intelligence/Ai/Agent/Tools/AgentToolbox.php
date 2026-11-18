<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Tools;

use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Commerce\Support\CartRestoreLink;
use App\Domain\Intelligence\Ai\Agent\Support\AgentRun;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Support\Money\Decimal;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Las herramientas del agente de ventas (inteligencia.md §6.7). Todas devuelven
 * hechos: el modelo solo sabe lo que ellas le dicen.
 *
 * - El precio y el stock salen **siempre en vivo** del sitio (`product.lookup`);
 *   la búsqueda en el catálogo del hub no los trae, para que el modelo tenga que
 *   consultarlos.
 * - El estado del pedido y los puntos son del contacto verificado del chat (su
 *   número de WhatsApp), nunca de un dato que escriba la persona.
 * - El carrito solo lleva cupón si las reglas de descuento lo permiten.
 */
final class AgentToolbox
{
    private const LOOKUP_TIMEOUT = 8;

    public function __construct(private readonly SendSiteCommand $command) {}

    /** @return list<AiTool> */
    public function for(AgentRun $run): array
    {
        return [
            $this->searchProducts($run),
            $this->getProduct($run),
            $this->getOrderStatus($run),
            $this->createCartLink($run),
            $this->getPolicies($run),
            $this->getLoyaltyBalance($run),
            $this->bookAppointment(),
            $this->handoff($run),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function schema(array $properties): array
    {
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    private function searchProducts(AgentRun $run): AiTool
    {
        return new AiTool('search_products',
            'Busca productos publicados del catálogo por nombre, marca o categoría. No trae precio ni stock: para eso usa get_product.',
            self::schema(['query' => ['type' => 'string', 'description' => 'Lo que busca la persona, p. ej. «pintura azul galón»'], 'limit' => ['type' => ['integer', 'null'], 'description' => 'Máximo de resultados (por defecto 5)']]),
            function (array $in) use ($run): array {
                $site = $run->site ?? throw new RuntimeException('No hay una tienda conectada.');
                $words = array_values(array_filter(preg_split('/\s+/u', trim((string) ($in['query'] ?? ''))) ?: [], fn ($w) => mb_strlen($w) >= 2));
                $limit = max(1, min(10, (int) ($in['limit'] ?? 5)));
                $categories = ProductCategory::query()->where('site_id', $site->id)->whereNull('deleted_at')->pluck('name', 'external_id');
                $query = Product::query()->where('site_id', $site->id)->whereNull('deleted_at')->whereNull('parent_external_id')->where('status', 'publish');
                foreach ($words as $word) {
                    $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
                    $catIds = $categories->filter(fn ($name) => Str::contains(Str::lower(Str::ascii((string) $name)), Str::lower(Str::ascii($word))))->keys()->all();
                    $query->where(function ($q) use ($like, $catIds) {
                        $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('brand', 'like', $like);
                        foreach ($catIds as $id) {
                            $q->orWhereJsonContains('category_ids', (string) $id);
                        }
                    });
                }
                $rows = $query->orderByDesc('purchases_30d')->limit($limit)->get(['external_id', 'name', 'brand', 'category_ids', 'permalink', 'stock_status']);

                return ['products' => $rows->map(fn (Product $p) => [
                    'product_id' => (string) $p->external_id,
                    'name' => (string) $p->name,
                    'brand' => $p->getAttribute('brand'),
                    'categories' => array_values(array_filter(array_map(fn ($id) => $categories[(string) $id] ?? null, (array) $p->getAttribute('category_ids')))),
                    'url' => $p->getAttribute('permalink'),
                ])->values()->all(), 'note' => 'Precio y disponibilidad: consulta get_product antes de decirlos.'];
            });
    }

    private function getProduct(AgentRun $run): AiTool
    {
        return new AiTool('get_product',
            'Precio, oferta, stock y variaciones EN VIVO de la tienda. Úsala siempre antes de decir un precio o si hay disponibilidad.',
            self::schema(['product_id' => ['type' => ['string', 'null'], 'description' => 'ID del producto (de search_products)'], 'search' => ['type' => ['string', 'null'], 'description' => 'Texto a buscar si no tienes el ID']]),
            function (array $in) use ($run): array {
                $site = $run->site ?? throw new RuntimeException('No hay una tienda conectada.');
                $id = trim((string) ($in['product_id'] ?? ''));
                $search = trim((string) ($in['search'] ?? ''));
                if ($id === '' && $search === '') {
                    throw new InvalidArgumentException('Indica product_id o search.');
                }
                $data = $this->command->handle($site, 'product.lookup', $id !== '' ? ['ids' => [$id], 'limit' => 1] : ['search' => mb_substr($search, 0, 80), 'limit' => 5], timeout: self::LOOKUP_TIMEOUT);
                $products = array_map(fn ($p) => self::product((array) $p), array_slice((array) ($data['products'] ?? []), 0, 5));

                return ['products' => $products, 'currency' => $site->currency ?: $run->workspace->currency, 'found' => $products !== []];
            });
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private static function product(array $p): array
    {
        return [
            'product_id' => (string) ($p['id'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'price' => $p['price'] ?? null,
            'regular_price' => $p['regular_price'] ?? null,
            'sale_price' => ($p['sale_price'] ?? '') !== '' ? $p['sale_price'] : null,
            'stock_status' => $p['stock_status'] ?? null,
            'stock_qty' => $p['stock_qty'] ?? null,
            'url' => $p['url'] ?? null,
            'variations' => array_map(fn ($v) => array_intersect_key((array) $v, array_flip(['id', 'name', 'attributes', 'price', 'stock_status', 'stock_qty'])),
                array_slice((array) ($p['variations'] ?? []), 0, 20)),
        ];
    }

    private function getOrderStatus(AgentRun $run): AiTool
    {
        return new AiTool('get_order_status',
            'Estado de un pedido de la persona del chat, por su número. Solo funciona con los pedidos hechos con el número de WhatsApp de este chat.',
            self::schema(['order_number' => ['type' => 'string', 'description' => 'Número del pedido, p. ej. 10683']]),
            function (array $in) use ($run): array {
                $site = $run->site ?? throw new RuntimeException('No hay una tienda conectada.');
                $phone = $run->contact?->phone;
                if ($phone === null || $phone === '') {
                    return ['found' => false, 'message' => 'No puedo verificar pedidos desde este chat.'];
                }
                $number = preg_replace('/[^0-9A-Za-z\-]/', '', (string) ($in['order_number'] ?? '')) ?? '';
                if ($number === '') {
                    throw new InvalidArgumentException('Indica el número del pedido.');
                }
                try {
                    $data = $this->command->handle($site, 'order.status', ['number' => $number, 'phone' => $phone], timeout: self::LOOKUP_TIMEOUT);
                } catch (RuntimeException $e) {
                    // Un número que no coincide responde igual que un pedido inexistente (api-connector.md §6).
                    return ['found' => false, 'order_number' => $number, 'message' => 'No encontré ese pedido con el número de este chat.'];
                }

                return ['found' => true, 'order_number' => $number] + array_intersect_key($data, array_flip(['status', 'status_label', 'total', 'currency', 'created_at', 'tracking']));
            });
    }

    private function createCartLink(AgentRun $run): AiTool
    {
        $rules = (array) ($run->settings->discount_rules ?? []);
        $discount = ! empty($rules['enabled']) && trim((string) ($rules['coupon_code'] ?? '')) !== ''
            ? ' Puede llevar el descuento autorizado: '.trim((string) ($rules['description'] ?? $rules['coupon_code'])).'.'
            : ' No hay descuentos autorizados.';

        return new AiTool('create_cart_link',
            'Arma el carrito con los productos y cantidades y devuelve el enlace para pagar, con los precios en vivo y el subtotal.'.$discount,
            self::schema([
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => (object) [
                    'product_id' => ['type' => 'string'], 'variation_id' => ['type' => ['string', 'null']], 'qty' => ['type' => 'integer'],
                ], 'required' => ['product_id', 'variation_id', 'qty'], 'additionalProperties' => false]],
                'apply_discount' => ['type' => 'boolean', 'description' => 'Aplicar el descuento autorizado, si lo hay y la compra cumple sus condiciones'],
            ]),
            function (array $in) use ($run, $rules): array {
                $site = $run->site ?? throw new RuntimeException('No hay una tienda conectada.');
                $wanted = [];
                foreach (array_slice((array) ($in['items'] ?? []), 0, 20) as $item) {
                    $item = (array) $item;
                    $qty = max(1, min(99, (int) ($item['qty'] ?? 1)));
                    $wanted[] = ['product_id' => (string) ($item['product_id'] ?? ''), 'variation_id' => ($item['variation_id'] ?? null) !== null ? (string) $item['variation_id'] : null, 'qty' => $qty];
                }
                if ($wanted === []) {
                    throw new InvalidArgumentException('El carrito está vacío.');
                }
                $data = $this->command->handle($site, 'product.lookup', ['ids' => array_values(array_unique(array_column($wanted, 'product_id'))), 'limit' => 20], timeout: self::LOOKUP_TIMEOUT);
                $live = [];
                foreach ((array) ($data['products'] ?? []) as $p) {
                    $live[(string) ((array) $p)['id']] = (array) $p;
                }
                $lines = [];
                $subtotal = '0';
                foreach ($wanted as $w) {
                    $p = $live[$w['product_id']] ?? null;
                    $variation = null;
                    foreach ((array) ($p['variations'] ?? []) as $v) {
                        if ((string) (((array) $v)['id'] ?? '') === (string) $w['variation_id']) {
                            $variation = (array) $v;
                        }
                    }
                    $source = $variation ?? $p;
                    if ($p === null || ($source['stock_status'] ?? 'instock') === 'outofstock' || ! is_numeric($source['price'] ?? null)) {
                        return ['ok' => false, 'unavailable' => $w['product_id'], 'message' => 'Ese producto no está disponible para comprar ahora.'];
                    }
                    $line = Decimal::mul((string) $source['price'], (string) $w['qty']);
                    $subtotal = Decimal::add($subtotal, $line);
                    $lines[] = ['product_id' => $w['product_id'], 'variation_id' => $w['variation_id'], 'name' => (string) ($p['name'] ?? ''), 'qty' => $w['qty'], 'unit_price' => (string) $source['price'], 'line_total' => $line];
                }
                $coupon = null;
                if (! empty($in['apply_discount']) && ! empty($rules['enabled']) && trim((string) ($rules['coupon_code'] ?? '')) !== ''
                    && (float) $subtotal >= (float) ($rules['min_subtotal'] ?? 0)) {
                    $coupon = trim((string) $rules['coupon_code']);
                }
                $cart = Cart::query()->create([
                    'workspace_id' => $run->workspace->id, 'site_id' => $site->id, 'token' => 'agent_'.Str::lower((string) Str::ulid()),
                    'contact_id' => $run->contact?->id, 'items' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'variation_id' => $l['variation_id'], 'qty' => $l['qty'], 'name' => $l['name'], 'price' => $l['unit_price']], $lines),
                    'items_count' => array_sum(array_column($lines, 'qty')), 'subtotal' => $subtotal, 'subtotal_ws' => $subtotal,
                    'currency' => $site->currency ?: $run->workspace->currency, 'coupons' => $coupon !== null ? [$coupon] : [],
                    'status' => 'agent', 'last_activity_at' => now(),
                ]);
                $url = CartRestoreLink::for($cart, $coupon) ?? throw new RuntimeException('No se pudo crear el enlace del carrito.');
                $run->cartId = $cart->id;

                return array_filter(['ok' => true, 'url' => $url, 'items' => $lines, 'subtotal' => $subtotal, 'currency' => $cart->currency,
                    'coupon' => $coupon, 'discount' => $coupon !== null ? ($rules['description'] ?? null) : null,
                    'note' => $coupon !== null ? 'El descuento se aplica al abrir el enlace; el total final lo muestra la tienda.' : null], fn ($v) => $v !== null);
            });
    }

    private function getPolicies(AgentRun $run): AiTool
    {
        return new AiTool('get_policies', 'Políticas del negocio: envíos, cambios y devoluciones, medios de pago, horarios y otras.',
            self::schema([]), fn (array $in) => ['policies' => $run->settings->policyTexts()]);
    }

    private function getLoyaltyBalance(AgentRun $run): AiTool
    {
        return new AiTool('get_loyalty_balance', 'Puntos y saldo a favor de la persona de este chat (solo los suyos).',
            self::schema([]), function (array $in) use ($run): array {
                $ledger = app(LoyaltyLedger::class);
                if ($run->contact === null || ! $ledger->isActive()) {
                    return ['available' => false, 'message' => 'Este negocio no tiene programa de puntos activo.'];
                }

                return ['available' => true, 'points' => $ledger->pointsBalance($run->contact->id), 'wallet' => $ledger->walletBalance($run->contact->id),
                    'points_name' => (string) (LoyaltyProgram::query()->value('currency_name') ?: 'puntos'), 'currency' => $run->workspace->currency];
            });
    }

    private function bookAppointment(): AiTool
    {
        return new AiTool('book_appointment', 'Agendar una cita (servicios).', self::schema(['when' => ['type' => ['string', 'null']]]),
            fn (array $in) => ['available' => false, 'message' => 'La agenda de citas todavía no está disponible; ofrece pasar con una persona.']);
    }

    private function handoff(AgentRun $run): AiTool
    {
        return new AiTool('handoff_to_human',
            'Pasa la conversación a una persona del equipo: si la piden, si hay una queja, un tema legal o médico, o algo que no puedes resolver con las herramientas.',
            self::schema(['reason' => ['type' => 'string', 'description' => 'Motivo corto, p. ej. «pide hablar con alguien», «queja por pedido»']]),
            function (array $in) use ($run): array {
                $run->handoffReason = mb_substr(trim((string) ($in['reason'] ?? 'pedido del agente')), 0, 64) ?: 'pedido del agente';

                return ['ok' => true];
            });
    }
}
