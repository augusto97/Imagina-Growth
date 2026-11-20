<?php

declare(strict_types=1);

namespace App\Support\Translation;

use Illuminate\Translation\FileLoader;

/**
 * Cargador de traducciones con idiomas regionales: `es_MX` y `es_VE` (y `es_CO`) llevan solo lo que cambia
 * y se mezclan encima de `es`, tanto los grupos (`lang/es/*.php`) como las frases (`lang/es.json`).
 *
 * Laravel ya cae al idioma de respaldo llave por llave en los grupos, pero no en las frases de JSON: sin
 * esto, un correo en `es_MX` perdería todo `lang/es.json`. Es la misma regla de la SPA (hub/CLAUDE.md §14).
 */
final class RegionalFileLoader extends FileLoader
{
    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<array-key, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        /** @var array<array-key, mixed> $lines */
        $lines = parent::load($locale, $group, $namespace);
        $base = self::baseOf((string) $locale);
        if ($base === null) {
            return $lines;
        }
        /** @var array<array-key, mixed> $baseLines */
        $baseLines = parent::load($base, $group, $namespace);

        return $group === '*' && $namespace === '*' ? array_merge($baseLines, $lines) : array_replace_recursive($baseLines, $lines);
    }

    /** `es_MX` o `es-MX` → `es`; un idioma sin región no tiene base. */
    public static function baseOf(string $locale): ?string
    {
        return preg_match('/^([a-z]{2,3})[_-][A-Za-z]{2,4}$/', $locale, $m) === 1 ? $m[1] : null;
    }
}
