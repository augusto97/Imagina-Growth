<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

use Illuminate\Http\JsonResponse;

/**
 * Forma única de los errores de la API pública (docs/api-publica.md §5):
 * `{"error": {"code": "validation_failed", "message": "…", "fields": {"email": ["…"]}}}`.
 * Los mensajes van en español; `code` es estable y en inglés para los programas.
 */
final class ApiError
{
    public const CODES = [
        400 => 'bad_request', 401 => 'unauthenticated', 402 => 'plan_required', 403 => 'forbidden', 404 => 'not_found',
        405 => 'method_not_allowed', 409 => 'conflict', 413 => 'too_large', 422 => 'validation_failed', 429 => 'rate_limited',
        500 => 'server_error', 503 => 'unavailable',
    ];

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, string $code, string $message, array $fields = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => __('Falta el token de la API o no es válido.'),
            402 => __('Tu plan no incluye la API pública.'),
            403 => __('Este token no tiene permiso para esta acción.'),
            404 => __('No encontramos lo que buscas.'),
            405 => __('Este método no está permitido aquí.'),
            409 => __('La petición choca con otra.'),
            413 => __('La petición es demasiado grande.'),
            422 => __('Revisa los datos enviados.'),
            429 => __('Demasiadas peticiones. Espera un momento.'),
            default => $status >= 500 ? __('Algo falló de nuestro lado. Intenta de nuevo.') : __('La petición no es válida.'),
        };
    }
}
