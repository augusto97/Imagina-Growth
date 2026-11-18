<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Lo común de los endpoints públicos de M12 (api-connector.md §8.3): el sitio por su
 * clave pública, CORS solo para los dominios del sitio y límites por IP (con hash) y
 * por `anonymous_id`.
 */
final class PublicSite
{
    public function __construct(private readonly SiteResolver $sites) {}

    /** @return array{0: Site, 1: Workspace}|null */
    public function resolve(string $publicKey): ?array
    {
        $resolved = $this->sites->byPublicKey($publicKey);
        if ($resolved === null || ! $resolved[0]->isActive()) {
            return null;
        }
        $workspace = Workspace::query()->find($resolved[0]->workspace_id);

        return $workspace === null ? null : [$resolved[0], $workspace];
    }

    /** @return array<string, string> */
    public static function cors(Request $request, Site $site): array
    {
        $origin = (string) $request->header('Origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $allowed = is_string($host) && in_array(strtolower($host), array_map('strtolower', (array) ($site->domains ?? [])), true);

        return ($allowed ? ['Access-Control-Allow-Origin' => $origin] : []) + [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Vary' => 'Origin',
        ];
    }

    /**
     * ¿Pasó algún límite? Cuenta el intento en cada clave (IP con hash y, si viene, el
     * navegador). Las claves no guardan la IP en claro.
     */
    public static function tooMany(string $bucket, Request $request, ?string $anonymousId, int $perIp, int $perVisitor): bool
    {
        $keys = ['igr-onsite:'.$bucket.':ip:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')) => $perIp];
        if ($anonymousId !== null && $anonymousId !== '') {
            $keys['igr-onsite:'.$bucket.':aid:'.sha1($anonymousId)] = $perVisitor;
        }
        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 60);
        }

        return false;
    }

    /** País de la petición (encabezado de Cloudflare), sin guardar la IP. */
    public static function country(Request $request): ?string
    {
        $country = strtoupper((string) $request->header('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 && ! in_array($country, ['XX', 'T1'], true) ? $country : null;
    }

    /** @return array<string, mixed>|null cuerpo JSON (text/plain para sendBeacon o application/json) */
    public static function body(Request $request): ?array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
