<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nadie puede mostrar el panel dentro de un iframe de otro sitio (clickjacking, docs/auditoria-2026-11.md S12). Las
 * páginas que fijan su propia política (reservas, reseñas, NPS) la conservan.
 */
final class FrameGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        return $response;
    }
}
