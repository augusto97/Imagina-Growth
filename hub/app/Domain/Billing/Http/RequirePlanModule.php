<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Contracts\Entitlements;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frena lo que crea algo nuevo de un módulo que el plan no incluye (M21): responde
 * 402 `plan_required` con el motivo en español. Leer lo que ya existe sigue abierto.
 * Uso en una ruta del panel: `->middleware(RequirePlanModule::class.':bookings')`.
 */
final class RequirePlanModule
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->entitlements->allows($this->current->get(), $module)) {
            return response()->json(['error' => 'plan_required', 'module' => $module, 'message' => self::message($module)], 402);
        }

        return $next($request);
    }

    public static function message(string $module): string
    {
        return match ($module) {
            'services' => __('Tu plan no incluye los leads de servicios. Cámbialo en Plan y facturación para crear leads.'),
            'bookings' => __('Tu plan no incluye la agenda de citas. Cámbialo en Plan y facturación para recibir reservas.'),
            'quotes' => __('Tu plan no incluye el cotizador. Cámbialo en Plan y facturación para crear cotizadores.'),
            'onsite' => __('Tu plan no incluye la captación. Cámbialo en Plan y facturación para crear páginas de captura y QR.'),
            default => __('Tu plan no incluye esta función. Cámbialo en Plan y facturación.'),
        };
    }
}
