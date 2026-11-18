<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Middleware;

use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `RequireScope::class.':contacts:read'`: el token debe tener ese alcance. */
final class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (! ApiContext::can($request, $scope)) {
            return ApiError::make(403, 'missing_scope', __('Este token no tiene el alcance «:scope».', ['scope' => $scope]));
        }

        return $next($request);
    }
}
