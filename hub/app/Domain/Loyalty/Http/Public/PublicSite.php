<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Http\Public;

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Lo común de los endpoints públicos de M13 (api-connector.md §8.3): el sitio
 * por su clave pública, CORS solo para sus dominios y límites por IP (con
 * hash, nunca la IP en claro).
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
    public function cors(Request $request, Site $site): array
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

    /** true si pasó el límite (y cuenta el intento). */
    public function limited(Request $request, string $bucket, int $perMinute): bool
    {
        $key = 'igr-loyalty:'.$bucket.':'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    /** @param array<string, mixed> $body */
    public function error(Request $request, Site $site, int $status, string $code, ?string $message = null, array $body = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => array_filter(['code' => $code, 'message' => $message])] + $body, $status)
            ->withHeaders($this->cors($request, $site) + ['Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> cuerpo JSON de un POST text/plain (sendBeacon) o application/json */
    public static function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
