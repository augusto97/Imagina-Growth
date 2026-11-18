<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Workspaces\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Solo el equipo de la plataforma (`users.is_platform_admin`), con 2FA si se exige. */
final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Un usuario recién creado en memoria puede no traer la columna: cuenta como «no».
        if (! $user instanceof User || ! (bool) ($user->getAttributes()['is_platform_admin'] ?? false)) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        if (config('igr.require_two_factor') && ! $user->hasTwoFactorEnabled()) {
            return response()->json(['error' => 'two_factor_required'], 403);
        }

        return $next($request);
    }
}
