<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;
use Ramsey\Uuid\Uuid;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Navegación de la tienda demo (sesiones del tracker y eventos de carrito del
 * connector) coherente con los pedidos de DemoStoreGenerator: cada pedido de
 * la ventana tiene su sesión de compra (aterrizaje → producto → carrito →
 * checkout → gracias) con el mismo anonymous_id y session_id que el pedido, y
 * el resto del tráfico son sesiones sin compra hasta una conversión realista.
 *
 * Usa su propio generador aleatorio para no alterar la secuencia de pedidos.
 */
final class DemoBrowsingGenerator
{
    /** Mezcla de canales del tráfico y probabilidad relativa de comprar (inteligencia.md §2). */
    private const CHANNELS = [
        'organic_search' => [30, 1.0], 'direct' => [17, 1.3], 'paid_meta' => [15, 0.8], 'paid_google' => [10, 1.5],
        'organic_social' => [8, 0.5], 'whatsapp' => [6, 1.8], 'paid_tiktok' => [4, 0.6], 'referral' => [4, 0.9],
        'email' => [3, 1.6], 'ai_assistants' => [3, 1.2],
    ];

    /** @var array<string, array{0: string, 1: int}> tipo => UA, peso */
    private const DEVICES = [
        'android' => ['Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 46],
        'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 22],
        'windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 20],
        'mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15', 7],
        'ipad' => ['Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1', 5],
    ];

    private const ZERO_RESULT_TERMS = ['pintura para piscina', 'estuco plástico', 'drywall', 'cemento blanco', 'lija 220', 'teja de barro', 'pintura magnética', 'sellador de madera'];

    private const BLOG_POSTS = ['como-pintar-una-fachada', 'cuanta-pintura-necesito', 'impermeabilizar-el-techo', 'colores-tendencia-2026', 'herramientas-basicas-del-hogar'];

    private readonly Randomizer $r;

    private readonly CarbonImmutable $from;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var list<string> visitantes que ya vinieron (para sesiones recurrentes) */
    private array $returningPool = [];

    private int $purchaseSessions = 0;

    private int $sequence = 0;

    /**
     * @param  list<array<string, mixed>>  $products  catálogo de DemoStoreGenerator
     */
    public function __construct(
        private readonly string $siteId,
        private readonly CarbonImmutable $until,
        private array $products,
        private readonly int $months = 12,
        int $seed = 2026,
        private readonly float $conversionRate = 0.016,
    ) {
        $this->r = new Randomizer(new Mt19937($seed + 7919));
        $this->from = $until->subMonths($months)->startOfDay();
    }

    /** @param list<array<string, mixed>> $products */
    public function withProducts(array $products): self
    {
        $this->products = $products;

        return $this;
    }

    public function covers(CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->from) && $at->lessThanOrEqualTo($this->until);
    }

    /**
     * Sesión de compra de un pedido: deja los eventos de navegación en el
     * búfer y devuelve la identidad y el contexto que lleva el pedido (como lo
     * adjunta el connector desde las cookies, eventos.md §2).
     *
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $order
     * @return array{identity: array<string, string>, context: array<string, mixed>}
     */
    public function purchaseSession(array $buyer, array $order, CarbonImmutable $createdAt): array
    {
        $this->purchaseSessions++;
        $aid = Uuid::uuid5(DemoStoreGenerator::UUID_NAMESPACE, 'aid:'.$buyer['email'])->toString();
        $sid = 's_'.substr(sha1($this->siteId.$order['order_id']), 0, 20);
        $channel = $this->pickChannel(true);
        $touch = $this->touch($channel, $createdAt);
        $device = $this->pickDevice();
        $geo = ['city' => (string) $buyer['city'], 'region' => (string) $buyer['state']];

        $at = $createdAt->subMinutes($this->r->getInt(4, 28));
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $first = $this->productById((string) ($items[0]['product_id'] ?? '')) ?? $this->pickProduct();
        $this->page($sid, $aid, $at, 'product', '/producto/'.$first['slug'].'/', $first['name'], $touch, $device, $geo, $first);
        foreach ($items as $i => $item) {
            $product = $this->productById((string) $item['product_id']);
            if ($product !== null && $i > 0) {
                $at = $at->addSeconds($this->r->getInt(20, 120));
                $this->page($sid, $aid, $at, 'product', '/producto/'.$product['slug'].'/', $product['name'], null, $device, $geo, $product);
            }
            $at = $at->addSeconds($this->r->getInt(10, 60));
            $this->server('cart.item_added', $sid, $aid, $at, $touch, $device, $geo, [
                'cart_token' => 'c_'.substr(sha1($sid), 0, 14), 'product_id' => (string) $item['product_id'],
                'qty' => $item['qty'], 'price' => $item['unit_price'], 'cart_total' => $order['totals']['subtotal'], 'currency' => 'COP',
            ]);
        }
        $at = $at->addSeconds($this->r->getInt(30, 180));
        $this->page($sid, $aid, $at, 'checkout', '/finalizar-compra/', 'Finalizar compra', null, $device, $geo);
        $this->server('checkout.started', $sid, $aid, $at->addSeconds(1), $touch, $device, $geo, [
            'cart_token' => 'c_'.substr(sha1($sid), 0, 14), 'subtotal' => $order['totals']['subtotal'],
            'items_count' => count($items), 'currency' => 'COP',
        ]);
        $this->page($sid, $aid, $createdAt->addSeconds(5), 'thankyou', '/finalizar-compra/order-received/'.$order['order_id'].'/', 'Pedido recibido', null, $device, $geo);
        $this->returningPool[] = $aid;

        return [
            'identity' => ['anonymous_id' => $aid, 'session_id' => $sid],
            'context' => ['page_type' => 'checkout', 'last_touch' => $touch['last_touch'], 'user_agent' => self::DEVICES[$device][0], 'currency' => 'COP', 'locale' => 'es-CO'],
        ];
    }

    /**
     * Sesiones sin compra hasta llegar a la conversión objetivo. Se llama
     * después de generar los pedidos.
     *
     * @return \Generator<int, list<array<string, mixed>>> lotes de sobres
     */
    public function backgroundSessions(int $chunk = 20000): \Generator
    {
        $target = (int) round($this->purchaseSessions / max(0.001, $this->conversionRate)) - $this->purchaseSessions;
        $days = [];
        $weights = [];
        $total = (int) $this->from->diffInDays($this->until);
        for ($d = 0, $day = $this->from; $d <= $total; $d++, $day = $day->addDay()) {
            $w = (1 + 0.4 * ($d / max(1, $total))) * [0.7, 1.05, 1.0, 1.0, 1.0, 1.0, 0.85][$day->dayOfWeek];
            $w *= match ($day->month) {
                12 => 1.35, 11 => 1.2, 5, 6 => 1.1, 1 => 0.85, default => 1.0
            };
            $w *= Holidays::isHoliday('CO', $day) ? 0.75 : 1.0;
            $days[] = $day;
            $weights[] = $w;
        }
        $cumulative = [];
        $sum = 0.0;
        foreach ($weights as $w) {
            $sum += $w;
            $cumulative[] = $sum;
        }
        $hours = [1, 1, 1, 1, 1, 2, 3, 5, 7, 8, 8, 8, 8, 8, 8, 8, 9, 9, 10, 11, 12, 11, 7, 3];
        for ($i = 0; $i < $target; $i++) {
            $day = $days[$this->bisect($cumulative, $this->float() * $sum)];
            $start = $day->setTime($this->pickIndex($hours), $this->r->getInt(0, 59), $this->r->getInt(0, 59));
            if ($start->greaterThan($this->until)) {
                continue;
            }
            $this->browseSession($start);
            if (count($this->buffer) >= $chunk) {
                yield $this->drain();
            }
        }
        if ($this->buffer !== []) {
            yield $this->drain();
        }
    }

    /** @return list<array<string, mixed>> */
    public function drain(): array
    {
        $out = $this->buffer;
        $this->buffer = [];

        return $out;
    }

    private function browseSession(CarbonImmutable $start): void
    {
        $returning = $this->returningPool !== [] && $this->roll() <= 25;
        $aid = $returning
            ? $this->returningPool[$this->r->getInt(0, count($this->returningPool) - 1)]
            : Uuid::uuid5(DemoStoreGenerator::UUID_NAMESPACE, 'visitor:'.(++$this->sequence))->toString();
        if (! $returning && $this->roll() <= 30) {
            $this->returningPool[] = $aid;
        }
        $sid = 's_'.substr(sha1($aid.$start->getTimestamp()), 0, 20);
        $channel = $this->pickChannel(false);
        $touch = $this->touch($channel, $start);
        $device = $this->pickDevice();
        $city = ColombiaData::CITIES[$this->pickIndex(array_column(ColombiaData::CITIES, 2))];
        $geo = ['city' => $city[0], 'region' => $city[1]];

        // Aterrizaje según el canal.
        $landing = match ($channel) {
            'direct', 'email' => 'home',
            'organic_social', 'ai_assistants', 'referral' => $this->roll() <= 40 ? 'blog' : 'product',
            default => $this->roll() <= 70 ? 'product' : 'category',
        };
        $pages = 1;
        while ($pages < 12 && $this->roll() <= 58) {
            $pages++;
        }
        $at = $start;
        $viewed = [];
        for ($p = 0; $p < $pages; $p++) {
            $type = $p === 0 ? $landing : ['product', 'product', 'product', 'category', 'category', 'home', 'blog', 'other'][$this->r->getInt(0, 7)];
            $product = null;
            [$path, $title] = match ($type) {
                'product' => (function () use (&$product, &$viewed): array {
                    $product = $this->pickProduct();
                    $viewed[] = $product;

                    return ['/producto/'.$product['slug'].'/', $product['name']];
                })(),
                'category' => (function () use (&$product): array {
                    $product = $this->pickProduct();

                    return ['/categoria/'.$product['category_slug'].'/', $product['category_name']];
                })(),
                'blog' => ['/blog/'.self::BLOG_POSTS[$this->r->getInt(0, count(self::BLOG_POSTS) - 1)].'/', 'Blog'],
                'home' => ['/', 'Inicio'],
                default => ['/contacto/', 'Contacto'],
            };
            $this->page($sid, $aid, $at, $type, $path, $title, $p === 0 ? $touch : null, $device, $geo, $type === 'product' ? $product : null, $type === 'category' ? $product : null);
            if ($this->roll() <= 35) {
                $seconds = $this->r->getInt(30, 240);
                $this->browser('page.engaged', $sid, $aid, $at->addSeconds(min($seconds, 200)), $path, $type, null, $device, $geo, ['seconds_visible' => $seconds, 'max_scroll_pct' => $this->r->getInt(50, 100)]);
            }
            $at = $at->addSeconds($this->r->getInt(15, 150));
        }

        if ($this->roll() <= 7) {
            $zero = $this->roll() <= 14;
            $query = $zero ? self::ZERO_RESULT_TERMS[$this->r->getInt(0, count(self::ZERO_RESULT_TERMS) - 1)] : mb_strtolower(explode(' ', (string) $this->pickProduct()['name'])[0].' '.explode(' ', (string) $this->pickProduct()['name'])[1]);
            $this->page($sid, $aid, $at, 'search', '/?s='.rawurlencode($query), 'Resultados', null, $device, $geo);
            $this->browser('search.performed', $sid, $aid, $at->addSecond(), '/', 'search', null, $device, $geo, ['query' => $query, 'results_count' => $zero ? 0 : $this->r->getInt(1, 24), 'engine' => 'wc']);
            $at = $at->addSeconds($this->r->getInt(10, 60));
        }
        $cta = $this->r->getInt(1, 1000);
        if ($cta <= 40 || $cta > 990) {
            $whatsapp = $cta <= 40;
            $this->browser('cta.clicked', $sid, $aid, $at, '/contacto/', 'other', null, $device, $geo, $whatsapp
                ? ['kind' => 'whatsapp', 'target' => 'https://wa.me/573001234567', 'label' => 'Escríbenos por WhatsApp', 'wa_ref' => $this->waRef()]
                : ['kind' => 'phone', 'target' => 'tel:+5768801234', 'label' => 'Llámanos']);
        }
        if ($this->r->getInt(1, 1000) <= 3) {
            $this->server('form.submitted', $sid, $aid, $at->addSeconds(20), $touch, $device, $geo, [
                'form_plugin' => 'cf7', 'form_id' => $this->r->getInt(0, 1) === 1 ? '12' : '15',
                'form_name' => $this->r->getInt(0, 1) === 1 ? 'Contacto' : 'Cotización de obra', 'page_path' => '/contacto/',
                'fields' => ['city' => $geo['city'], 'service' => 'Asesoría de color'], 'consents' => new \stdClass,
            ]);
        }
        if ($viewed !== [] && $this->roll() <= 9) {
            $product = $viewed[$this->r->getInt(0, count($viewed) - 1)];
            $token = 'c_'.substr(sha1($sid), 0, 14);
            $this->server('cart.item_added', $sid, $aid, $at, $touch, $device, $geo, [
                'cart_token' => $token, 'product_id' => (string) $product['product_id'], 'qty' => 1,
                'price' => $product['price'], 'cart_total' => $product['price'], 'currency' => 'COP',
            ]);
            if ($this->roll() <= 42) {
                $at = $at->addSeconds($this->r->getInt(30, 200));
                $this->page($sid, $aid, $at, 'checkout', '/finalizar-compra/', 'Finalizar compra', null, $device, $geo);
                $this->server('checkout.started', $sid, $aid, $at->addSecond(), $touch, $device, $geo, [
                    'cart_token' => $token, 'subtotal' => $product['price'], 'items_count' => 1, 'currency' => 'COP',
                ]);
            }
        }
    }

    /**
     * @param  array{context: array<string, mixed>, last_touch: array<string, mixed>}|null  $touch
     * @param  array{city: string, region: string}  $geo
     * @param  array<string, mixed>|null  $product
     * @param  array<string, mixed>|null  $category
     */
    private function page(string $sid, string $aid, CarbonImmutable $at, string $type, string $path, string $title, ?array $touch, string $device, array $geo, ?array $product = null, ?array $category = null): void
    {
        $this->browser('page.viewed', $sid, $aid, $at, $path, $type, $touch, $device, $geo, array_filter([
            'page_type' => $type,
            'object_id' => $product !== null ? (string) $product['product_id'] : ($category !== null ? (string) $category['category_ids'][0] : null),
            'object_type' => $product !== null ? 'product' : ($category !== null ? 'product_cat' : null),
        ], fn ($v) => $v !== null), $title);
        if ($product !== null) {
            $this->browser('product.viewed', $sid, $aid, $at->addSecond(), $path, 'product', null, $device, $geo, [
                'product_id' => (string) $product['product_id'], 'sku' => $product['sku'], 'name' => $product['name'],
                'category_ids' => $product['category_ids'], 'price' => $product['price'], 'currency' => 'COP',
                'in_stock' => $product['stock_status'] === 'instock',
            ]);
        }
        if ($category !== null) {
            $this->browser('category.viewed', $sid, $aid, $at->addSecond(), $path, 'category', null, $device, $geo, [
                'category_id' => (string) $category['category_ids'][0], 'name' => $category['category_name'],
            ]);
        }
    }

    /**
     * @param  array{context: array<string, mixed>, last_touch: array<string, mixed>}|null  $touch
     * @param  array{city: string, region: string}  $geo
     * @param  array<string, mixed>  $properties
     */
    private function browser(string $event, string $sid, string $aid, CarbonImmutable $at, string $path, string $type, ?array $touch, string $device, array $geo, array $properties, string $title = ''): void
    {
        $context = ['page' => ['url' => 'https://demo.tienda.co'.$path, 'path' => $path, 'title' => $title, 'referrer' => 'https://demo.tienda.co/'], 'page_type' => $type,
            'user_agent' => self::DEVICES[$device][0], 'consent' => ['analytics' => true, 'marketing' => $this->roll() <= 70, 'source' => 'wp-consent-api'], 'locale' => 'es-CO', 'currency' => 'COP'];
        if ($touch !== null) {
            $context = array_replace_recursive($context, $touch['context']);
        }
        $this->buffer[] = $this->envelope($event, 'browser', $sid, $aid, $at, $context, $properties, $geo);
    }

    /**
     * @param  array{context: array<string, mixed>, last_touch: array<string, mixed>}  $touch
     * @param  array{city: string, region: string}  $geo
     * @param  array<string, mixed>  $properties
     */
    private function server(string $event, string $sid, string $aid, CarbonImmutable $at, array $touch, string $device, array $geo, array $properties): void
    {
        $context = ['last_touch' => $touch['last_touch'], 'user_agent' => self::DEVICES[$device][0], 'currency' => 'COP', 'locale' => 'es-CO', 'consent' => ['analytics' => true, 'marketing' => true, 'source' => 'wp-consent-api']];
        $this->buffer[] = $this->envelope($event, 'server', $sid, $aid, $at, $context, $properties, $geo);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $properties
     * @param  array{city: string, region: string}  $geo
     * @return array<string, mixed>
     */
    private function envelope(string $event, string $type, string $sid, string $aid, CarbonImmutable $at, array $context, array $properties, array $geo): array
    {
        return [
            'schema_version' => 2,
            'event_id' => Uuid::uuid5(DemoStoreGenerator::UUID_NAMESPACE, $sid.':'.$event.':'.(++$this->sequence))->toString(),
            'event' => $event,
            'occurred_at' => $at->toIso8601String(),
            'source' => ['type' => $type, 'platform' => 'woocommerce', 'site_id' => $this->siteId, 'sdk' => $type === 'browser' ? 'tracker/0.1.0' : 'connector/0.1.0'],
            'identity' => ['anonymous_id' => $aid, 'session_id' => $sid],
            'context' => $context,
            'properties' => $properties,
            // Simula el geo por IP que hará el hub con GeoLite2 (la IP nunca se guarda).
            '_geo' => ['country' => 'CO', 'region' => $geo['region'], 'city' => $geo['city']],
        ];
    }

    /** @return array{context: array<string, mixed>, last_touch: array<string, mixed>} */
    private function touch(string $channel, CarbonImmutable $at): array
    {
        $campaigns = ['temporada-pintura', 'black-friday', 'prima-junio', 'hogar-2026', 'remarketing'];
        $campaign = $campaigns[$this->r->getInt(0, count($campaigns) - 1)];
        $click = fn () => bin2hex($this->r->getBytes(10));
        [$utm, $clicks, $referrer] = match ($channel) {
            'organic_search' => [[], [], $this->roll() <= 92 ? 'https://www.google.com/' : 'https://www.bing.com/'],
            'paid_meta' => [['source' => $this->r->getInt(0, 1) === 1 ? 'facebook' : 'instagram', 'medium' => 'paid_social', 'campaign' => $campaign], ['fbclid' => 'IwAR'.$click()], 'https://l.facebook.com/'],
            'paid_google' => [['source' => 'google', 'medium' => 'cpc', 'campaign' => $campaign], ['gclid' => 'Cj0K'.$click()], 'https://www.google.com/'],
            'paid_tiktok' => [['source' => 'tiktok', 'medium' => 'paid', 'campaign' => $campaign], ['ttclid' => 'E.C.P.'.$click()], ''],
            'organic_social' => [[], [], $this->r->getInt(0, 1) === 1 ? 'https://l.instagram.com/' : 'https://m.facebook.com/'],
            'whatsapp' => [[], [], 'https://l.wl.co/'],
            'email' => [['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'boletin-'.$at->format('Y-m')], [], ''],
            'referral' => [[], [], 'https://www.blogdeconstruccion.co/guias/pintura'],
            'ai_assistants' => [[], [], $this->roll() <= 70 ? 'https://chatgpt.com/' : 'https://www.perplexity.ai/'],
            default => [[], [], ''],
        };
        $context = ['page' => ['referrer' => $referrer]];
        if ($utm !== []) {
            $context['utm'] = $utm;
        }
        if ($clicks !== []) {
            $context['click_ids'] = $clicks;
        }
        $lastTouch = array_filter(['utm' => $utm ?: null, 'click_ids' => $clicks ?: null, 'referrer' => $referrer !== '' ? $referrer : null, 'at' => $at->toIso8601String()]);

        return ['context' => $context, 'last_touch' => $lastTouch];
    }

    private function pickChannel(bool $buyer): string
    {
        $weights = array_map(fn ($c) => $buyer ? $c[0] * $c[1] : $c[0], self::CHANNELS);

        return array_keys(self::CHANNELS)[$this->pickIndex(array_values($weights))];
    }

    private function pickDevice(): string
    {
        return array_keys(self::DEVICES)[$this->pickIndex(array_column(self::DEVICES, 1))];
    }

    /** @return array<string, mixed> */
    private function pickProduct(): array
    {
        return $this->products[$this->pickIndex(array_column($this->products, 'popularity'))];
    }

    /** @return array<string, mixed>|null */
    private function productById(string $id): ?array
    {
        foreach ($this->products as $p) {
            if ($p['product_id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    private function waRef(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return implode('', array_map(fn () => $alphabet[$this->r->getInt(0, 31)], range(1, 4)));
    }

    /** @param list<float|int> $weights */
    private function pickIndex(array $weights): int
    {
        $sum = array_sum($weights);
        $x = $this->float() * $sum;
        foreach ($weights as $i => $w) {
            $x -= $w;
            if ($x <= 0) {
                return $i;
            }
        }

        return count($weights) - 1;
    }

    /** @param list<float> $cumulative */
    private function bisect(array $cumulative, float $x): int
    {
        $lo = 0;
        $hi = count($cumulative) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($cumulative[$mid] < $x) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    private function float(): float
    {
        return $this->r->getInt(0, 1_000_000) / 1_000_000;
    }

    /**
     * Tirada de 1 a 100. Cada llamada es un número nuevo del generador.
     *
     * @phpstan-impure
     */
    private function roll(): int
    {
        return $this->r->getInt(1, 100);
    }
}
