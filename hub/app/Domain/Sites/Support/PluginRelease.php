<?php

declare(strict_types=1);

namespace App\Domain\Sites\Support;

use ZipArchive;

/**
 * Versión del plugin que trae esta versión del hub (`public/downloads/imagina-growth-connector.zip`, la que
 * descargan los clientes desde el panel; en desarrollo, la del código del connector). Sirve para avisar qué sitios
 * tienen el plugin viejo (F15).
 */
final class PluginRelease
{
    public const ZIP = 'downloads/imagina-growth-connector.zip';

    private const MAIN_FILE = 'imagina-growth-connector.php';

    public static function bundledVersion(?string $zip = null, ?string $source = null): ?string
    {
        $zip ??= public_path(self::ZIP);
        if (is_file($zip) && class_exists(ZipArchive::class)) {
            $archive = new ZipArchive;
            if ($archive->open($zip) === true) {
                try {
                    for ($i = 0; $i < $archive->numFiles; $i++) {
                        $name = (string) $archive->getNameIndex($i);
                        if (basename($name) === self::MAIN_FILE && substr_count(trim($name, '/'), '/') <= 1) {
                            $version = self::parse((string) $archive->getFromIndex($i));
                            if ($version !== null) {
                                return $version;
                            }
                        }
                    }
                } finally {
                    $archive->close();
                }
            }
        }
        $source ??= base_path('../connector/'.self::MAIN_FILE);

        return is_file($source) ? self::parse((string) file_get_contents($source, length: 8192)) : null;
    }

    /** La cabecera «Version: X.Y.Z» del archivo principal de un plugin de WordPress. */
    public static function parse(string $header): ?string
    {
        return preg_match('/^[ \t\/*#@]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.\-]*)/mi', $header, $m) === 1 ? $m[1] : null;
    }

    /** ¿`$version` es más vieja que `$than`? Una versión desconocida (null) cuenta como vieja. */
    public static function older(?string $version, string $than): bool
    {
        return $version === null || $version === '' || version_compare($version, $than, '<');
    }
}
