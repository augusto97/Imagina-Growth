<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Workspaces\Models\Workspace;

/**
 * El idioma de Laravel de un espacio de trabajo (`es-MX` → `es_MX`) y la forma de trabajar con él: lo
 * aplican `ResolveWorkspace` en cada petición, `SetWorkspaceContext` en cada job, la detección de
 * hallazgos y el PDF del reporte mensual. `lang/es_MX` y `lang/es_VE` solo llevan lo que cambia
 * (`RegionalFileLoader`; hub/CLAUDE.md §14).
 */
final class WorkspaceLocale
{
    /** Los idiomas del panel (los mismos que valida `WorkspaceController`). */
    public const SUPPORTED = ['es-CO', 'es-MX', 'es-VE'];

    /** El idioma de Laravel del espacio de trabajo; uno que no es del panel cae al de la app. */
    public static function of(Workspace $workspace): string
    {
        $locale = (string) $workspace->getAttribute('locale');

        return in_array($locale, self::SUPPORTED, true) ? str_replace('-', '_', $locale) : (string) config('app.locale');
    }

    /**
     * Ejecuta el callback con el idioma del espacio de trabajo y restaura el anterior al terminar, también
     * si falla (los workers y las pruebas reutilizan la misma aplicación).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Workspace $workspace, callable $callback): mixed
    {
        $previous = app()->getLocale();
        $locale = self::of($workspace);
        if ($locale !== $previous) {
            app()->setLocale($locale);
        }
        try {
            return $callback();
        } finally {
            if (app()->getLocale() !== $previous) {
                app()->setLocale($previous);
            }
        }
    }
}
