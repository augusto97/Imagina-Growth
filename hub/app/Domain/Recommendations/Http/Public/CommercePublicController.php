<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Http\Public;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Commerce\Data\RecommendationContext;
use App\Domain\Recommendations\Banners\ResolveBanner;
use App\Domain\Recommendations\Engine\RecommendationEngine;
use App\Domain\Recommendations\Models\CommerceSettings;
use App\Domain\Recommendations\Models\OnsiteBanner;
use App\Domain\Recommendations\Support\VisitorResolver;
use App\Domain\Recommendations\Watches\SubscribeToProduct;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Endpoints públicos de comercio en el sitio (api-connector.md §8.3, M16):
 * recomendaciones, «avísame» y banner personalizado. Sin firma: el sitio se
 * identifica con su clave pública, CORS solo para sus dominios y límites por IP
 * (con hash) y por navegador. Nunca devuelven datos personales sin un
 * `igrUser.token` válido.
 */
final class CommercePublicController
{
    /** Tipos que no dependen de la persona: caché pública (CDN). */
    private const PUBLIC_KINDS = ['bought_together', 'best_sellers_category', 'trending'];

    public function __construct(private readonly SiteResolver $sites, private readonly CurrentWorkspace $current) {}

    /** GET /p/{publicKey}/reco?kind=&product_id=&category_id=&cart=88,91&aid=&limit=4 */
    public function reco(Request $request, string $publicKey, RecommendationService $service, VisitorResolver $visitors): JsonResponse
    {
        [$site, $workspace] = $this->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $cors = $this->cors($request, $site);
        if ($this->limited('reco', $request, (int) config('igr-commerce.recommendations.rate_per_minute', 240))) {
            return response()->json(['error' => 'rate_limited'], 429)->withHeaders($cors);
        }
        $kind = (string) $request->query('kind', '');
        if (! in_array($kind, RecommendationService::KINDS, true)) {
            return response()->json(['error' => 'invalid_kind', 'kinds' => RecommendationService::KINDS], 422)->withHeaders($cors);
        }
        $limit = max(1, min((int) $request->query('limit', '4'), (int) config('igr-commerce.recommendations.max_limit', 12)));
        $productId = self::externalId($request->query('product_id'));
        $categoryId = self::externalId($request->query('category_id'));
        $cart = [];
        foreach (explode(',', is_string($request->query('cart')) ? (string) $request->query('cart') : '') as $id) {
            if (($clean = self::externalId(trim($id))) !== null) {
                $cart[] = $clean;
            }
        }
        $cart = array_slice(array_values(array_unique($cart)), 0, 30);
        $personal = ! in_array($kind, self::PUBLIC_KINDS, true);
        $anonymousId = $personal ? VisitorResolver::cleanAnonymousId($request->query('aid')) : null;

        $body = $this->current->run($workspace, function () use ($service, $visitors, $site, $workspace, $kind, $limit, $productId, $categoryId, $cart, $personal, $anonymousId): array {
            $settings = CommerceSettings::current()->recommendationSettings();
            if (! $settings['enabled'] || ! $settings['kinds'][$kind]) {
                return ['kind' => $kind, 'products' => [], 'fallback' => false];
            }
            $context = new RecommendationContext(
                siteId: $site->id,
                contactId: $personal ? $visitors->fromAnonymous($anonymousId) : null,
                anonymousId: $anonymousId,
                productId: $productId,
                categoryId: $categoryId,
                cartProductIds: $cart,
            );
            $compute = function () use ($service, $kind, $context, $limit): array {
                $result = $service instanceof RecommendationEngine
                    ? $service->recommendWithSource($kind, $context, $limit)
                    : ['products' => $service->recommend($kind, $context, $limit), 'fallback' => false];

                return ['kind' => $kind] + $result;
            };
            if ($personal) {
                return $compute();
            }
            // Los tipos generales se guardan unos segundos también del lado del hub.
            $key = RedisKey::for($workspace->id, 'reco:'.$site->id.':'.md5(json_encode([$kind, $productId, $categoryId, $cart, $limit]) ?: ''));

            return Cache::remember($key, (int) config('igr-commerce.recommendations.cache_seconds', 60), $compute);
        });

        return response()->json($body)->withHeaders($cors + [
            'Cache-Control' => $personal ? 'private, max-age=60' : 'public, max-age=60',
        ]);
    }

    /** POST /p/{publicKey}/watch (text/plain con JSON) */
    public function watch(Request $request, string $publicKey, SubscribeToProduct $subscribe): JsonResponse
    {
        [$site, $workspace] = $this->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $cors = $this->cors($request, $site) + ['Cache-Control' => 'private, no-store'];
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $anonymousId = VisitorResolver::cleanAnonymousId($body['anonymous_id'] ?? null);
        if ($this->limited('watch', $request, (int) config('igr-commerce.watches.per_ip_per_minute', 10))
            || ($anonymousId !== null && $this->limitedKey('watch:aid:'.$anonymousId, (int) config('igr-commerce.watches.per_visitor_per_minute', 5)))) {
            return response()->json(['error' => 'rate_limited'], 429)->withHeaders($cors);
        }

        $productId = self::externalId($body['product_id'] ?? null);
        $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
        $email = is_string($body['email'] ?? null) && trim($body['email']) !== '' ? mb_substr(trim($body['email']), 0, 190) : null;
        $phone = is_string($body['phone'] ?? null) && trim($body['phone']) !== '' ? mb_substr(trim($body['phone']), 0, 40) : null;
        $errors = [];
        if ($productId === null) {
            $errors['product_id'] = [__('Falta el producto.')];
        }
        if (! in_array($kind, ['back_in_stock', 'price_drop'], true)) {
            $errors['kind'] = [__('Tipo de aviso no válido.')];
        }
        if ($email === null && $phone === null) {
            $errors['email'] = [__('Escribe un correo o un WhatsApp válido.')];
        }
        // Versión del texto legal que vio la persona (opcional). CaptureContact registra la vigente,
        // que es la misma que sirve widgets.json salvo en los minutos de caché tras un cambio.
        if (isset($body['consent_version']) && (! is_string($body['consent_version']) || mb_strlen($body['consent_version']) > 32)) {
            $errors['consent_version'] = [__('Versión del texto legal no válida.')];
        }
        if ($errors !== []) {
            return response()->json(['error' => 'invalid', 'errors' => $errors], 422)->withHeaders($cors);
        }
        $consents = [];
        foreach ((array) ($body['consents'] ?? []) as $channel => $checked) {
            if (is_string($channel)) {
                $consents[$channel] = $checked === true;
            }
        }

        try {
            $result = $this->current->run($workspace, fn () => $subscribe->handle($workspace, $site, [
                'product_id' => (string) $productId,
                'kind' => $kind,
                'email' => $email,
                'phone' => $phone,
                'first_name' => is_string($body['first_name'] ?? null) ? $body['first_name'] : null,
                'consents' => $consents,
                'anonymous_id' => $anonymousId,
                'page_url' => is_string($body['page_url'] ?? null) ? $body['page_url'] : null,
            ], $request->ip(), $request->userAgent()));
        } catch (ValidationException $e) {
            return response()->json(['error' => 'invalid', 'errors' => $e->errors()], 422)->withHeaders($cors);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'errors' => ['email' => [$e->getMessage()]]], 422)->withHeaders($cors);
        }

        if ($result['available']) {
            return response()->json(['ok' => true, 'available' => true, 'message' => __('¡Buenas noticias! Este producto ya está disponible.')])->withHeaders($cors);
        }
        $channel = $result['watch']?->channel;
        $message = match (true) {
            $kind === 'price_drop' && $channel === 'whatsapp' => __('Listo. Te escribiremos por WhatsApp si baja de precio.'),
            $kind === 'price_drop' => __('Listo. Te avisaremos si baja de precio.'),
            $channel === 'whatsapp' => __('Listo. Te escribiremos por WhatsApp cuando vuelva.'),
            default => __('Listo. Te avisaremos cuando vuelva.'),
        };

        return response()->json(['ok' => true, 'message' => $message])->withHeaders($cors);
    }

    /** GET /p/{publicKey}/banner?placement=&aid=&token= */
    public function banner(Request $request, string $publicKey, ResolveBanner $resolve): JsonResponse
    {
        [$site, $workspace] = $this->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $cors = $this->cors($request, $site) + ['Cache-Control' => 'private, no-store'];
        if ($this->limited('banner', $request, (int) config('igr-commerce.banners.rate_per_minute', 120))) {
            return response()->json(['error' => 'rate_limited'], 429)->withHeaders($cors);
        }
        $placement = in_array($request->query('placement'), OnsiteBanner::PLACEMENTS, true) ? (string) $request->query('placement') : 'top';
        $token = is_string($request->query('token')) ? (string) $request->query('token') : null;
        $aid = is_string($request->query('aid')) ? (string) $request->query('aid') : null;
        $banner = $this->current->run($workspace, fn () => $resolve->handle($site, $placement, $aid, $token));

        return response()->json(['banner' => $banner])->withHeaders($cors);
    }

    /** @return array{0: Site, 1: Workspace}|null */
    private function resolve(string $publicKey): ?array
    {
        $resolved = $this->sites->byPublicKey($publicKey);
        if ($resolved === null || ! $resolved[0]->isActive()) {
            return null;
        }
        $workspace = Workspace::query()->find($resolved[0]->workspace_id);

        return $workspace === null ? null : [$resolved[0], $workspace];
    }

    /** @return array<string, string> */
    private function cors(Request $request, Site $site): array
    {
        $origin = (string) $request->header('Origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $allowed = is_string($host) && in_array(strtolower($host), array_map('strtolower', $site->domains ?? []), true);

        return ($allowed ? ['Access-Control-Allow-Origin' => $origin] : []) + [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Vary' => 'Origin',
        ];
    }

    /** Límite por IP con hash (nunca se guarda la IP en claro). */
    private function limited(string $bucket, Request $request, int $perMinute): bool
    {
        return $this->limitedKey('igr-'.$bucket.':'.hash('sha256', (string) $request->ip()), $perMinute);
    }

    private function limitedKey(string $key, int $perMinute): bool
    {
        if (RateLimiter::tooManyAttempts($key, max(1, $perMinute))) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** ID externo de producto o categoría (el de la tienda): texto corto sin caracteres raros. */
    private static function externalId(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value) === 1 ? $value : null;
    }
}
