<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Middleware;

use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use App\Domain\Api\Support\ClientResolver;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Límite de peticiones por token y minuto (del plan: `api_requests_per_minute`, 120 por
 * defecto). Responde 429 con `Retry-After` y siempre manda las cabeceras `X-RateLimit-*`.
 */
final class ThrottleApiClient
{
    public function __construct(private readonly ClientResolver $resolver, private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = ApiContext::client($request);
        $limit = $this->resolver->perMinute($this->current->get());
        $key = 'api:rate:'.$client->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = RateLimiter::availableIn($key);

            return ApiError::make(429, 'rate_limited', ApiError::defaultMessage(429), headers: [
                'Retry-After' => (string) $retry, 'X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => '0',
            ]);
        }
        RateLimiter::hit($key, 60);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

        return $response;
    }
}
