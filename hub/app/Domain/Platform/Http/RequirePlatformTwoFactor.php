<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Workspaces\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los ajustes de la plataforma, los festivos, las tarifas y los administradores piden siempre la verificación en dos
 * pasos, aunque la instalación no la exija a todos (va después de EnsurePlatformAdmin): desde aquí se puede apagar la
 * propia exigencia de 2FA y se ven los datos de las apps de terceros.
 */
final class RequirePlatformTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            return response()->json(['error' => 'two_factor_required', 'message' => __('Activa la verificación en dos pasos para cambiar los ajustes de la plataforma.')], 403);
        }

        return $next($request);
    }
}
