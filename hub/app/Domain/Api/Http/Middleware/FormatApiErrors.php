<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Middleware;

use App\Domain\Api\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lleva los errores de Laravel (validación, 404, 405, 500…) a la forma de la API pública:
 * `{"error": {"code", "message", "fields"?}}`. Los errores que ya vienen en esa forma no se tocan.
 */
final class FormatApiErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }
        $data = $response instanceof JsonResponse ? $response->getData(true) : null;
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            return $response;
        }
        $fields = is_array($data) && isset($data['errors']) && is_array($data['errors']) ? $data['errors'] : [];
        $message = $status === 422 && $fields !== [] ? ApiError::defaultMessage(422) : ApiError::defaultMessage($status);
        $formatted = ApiError::make($status, ApiError::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'bad_request'), $message, $fields);
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Allow', 'WWW-Authenticate'] as $header) {
            if ($response->headers->has($header)) {
                $formatted->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $formatted;
    }
}
