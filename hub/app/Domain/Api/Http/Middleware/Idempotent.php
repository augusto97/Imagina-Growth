<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Middleware;

use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use App\Support\Tenancy\RedisKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` en las escrituras (docs/api-publica.md §6): la misma clave con el mismo
 * cuerpo devuelve la respuesta guardada (24 h) con `Idempotent-Replayed: true`; con otro
 * cuerpo responde 409. Mientras la primera petición corre, otra igual recibe 409.
 */
final class Idempotent
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return $next($request);
        }
        if (strlen($key) > 255) {
            return ApiError::make(400, 'bad_request', __('La clave Idempotency-Key no puede pasar de 255 caracteres.'));
        }
        $client = ApiContext::client($request);
        $base = RedisKey::for($client->workspace_id, 'api:idem:'.$client->id.':'.hash('sha256', $key));
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        $stored = Cache::get($base);
        if (is_array($stored)) {
            if (($stored['fingerprint'] ?? null) !== $fingerprint) {
                return ApiError::make(409, 'idempotency_conflict', __('Esa Idempotency-Key ya se usó con otra petición.'));
            }
            $replay = new JsonResponse($stored['body'] ?? null, (int) ($stored['status'] ?? 200));
            $replay->headers->set('Idempotent-Replayed', 'true');

            return $replay;
        }
        if (! Cache::add($base.':lock', 1, 30)) {
            return ApiError::make(409, 'idempotency_in_progress', __('Una petición con esa Idempotency-Key aún se está procesando.'));
        }
        try {
            $response = $next($request);
            if ($response->getStatusCode() < 500 && $response instanceof JsonResponse) {
                Cache::put($base, ['fingerprint' => $fingerprint, 'status' => $response->getStatusCode(), 'body' => $response->getData(true)], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            Cache::forget($base.':lock');
        }
    }
}
