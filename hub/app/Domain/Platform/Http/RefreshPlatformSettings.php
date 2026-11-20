<?php

declare(strict_types=1);

namespace App\Domain\Platform\Http;

use App\Domain\Platform\Settings\PlatformSettingsRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En cada petición, si los ajustes de la plataforma cambiaron desde que se aplicaron en este proceso, los vuelve a
 * aplicar (F17). Con FrankenPHP clásico cada petición arranca de cero y ya los leyó al arrancar: entonces no repite la
 * consulta. Sirve para los procesos que atienden varias peticiones (y para las pruebas).
 */
final class RefreshPlatformSettings
{
    public function __construct(private readonly PlatformSettingsRuntime $runtime) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = $request->server('REQUEST_TIME_FLOAT');
        if (is_numeric($started) && $this->runtime->checkedAt() < (float) $started) {
            $this->runtime->refreshIfStale();
        }

        return $next($request);
    }
}
