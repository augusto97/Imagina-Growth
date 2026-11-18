<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Versión instalada del hub (docs/despliegue.md §4): el archivo `VERSION` de la raíz de la
 * versión (en el servidor, `/opt/growth/releases/<versión>/VERSION`; en desarrollo, el del
 * repositorio). `dev` si no existe. La lee `config('app.version')`, que queda en la caché
 * de configuración de cada versión.
 */
final class AppVersion
{
    public static function current(): string
    {
        $version = config('app.version');

        return is_string($version) && $version !== '' ? $version : 'dev';
    }

    /** Lee y valida un archivo VERSION (X.Y.Z, con sufijo opcional); `dev` si falta o no es válido. */
    public static function fromFile(string $path): string
    {
        if (! is_file($path)) {
            return 'dev';
        }
        $value = trim((string) file_get_contents($path));

        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $value) === 1 ? $value : 'dev';
    }
}
