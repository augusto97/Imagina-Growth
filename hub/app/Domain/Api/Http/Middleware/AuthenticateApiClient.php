<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Middleware;

use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use App\Domain\Api\Support\ApiMeter;
use App\Domain\Api\Support\ClientResolver;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica la API pública con un token del espacio de trabajo (`Authorization: Bearer igr_…`),
 * revisa que el plan incluya la API, fija el espacio de trabajo y suma la petición al medidor.
 */
final class AuthenticateApiClient
{
    public function __construct(private readonly ClientResolver $resolver, private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, string $module = 'api'): Response
    {
        $result = $this->resolver->resolve($request->bearerToken(), $module === 'mcp' ? 'mcp' : 'api');
        if (isset($result['error'])) {
            [$status, $code, $message] = $result['error'];

            return ApiError::make($status, $code, $message, headers: $status === 401 ? ['WWW-Authenticate' => 'Bearer'] : []);
        }
        $client = $result['client'];
        $request->attributes->set(ApiContext::ATTRIBUTE, $client);
        $this->current->set($result['workspace']);
        ApiMeter::hit($result['workspace']);

        return $next($request);
    }
}
