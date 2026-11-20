<?php

declare(strict_types=1);

namespace App\Domain\Sites\Support;

use App\Domain\Sites\Models\Site;
use ZipArchive;

/**
 * Versiones del plugin que conoce el hub (connector/CLAUDE.md §15, api-connector.md §5.1):
 * - la mínima compatible y la última, que viajan en `/v1/s/config` para que el plugin avise en el admin de WordPress
 *   sin pedir nada más al hub (`forSite`);
 * - la que trae este paquete en `public/downloads/imagina-growth-connector.zip` (la que descargan los clientes desde
 *   el panel; en desarrollo, la del código del connector), con la que el diagnóstico avisa qué sitios tienen el
 *   plugin viejo (F15, `bundledVersion`).
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

    public static function minVersion(): string
    {
        return (string) config('igr.min_plugin_version');
    }

    public static function latestVersion(): string
    {
        return (string) config('igr.plugin.latest_version');
    }

    public static function downloadUrl(): string
    {
        return rtrim((string) config('igr.urls.app'), '/').(string) config('igr.plugin.download_path');
    }

    /**
     * `outdated`: el sitio usa una versión menor que la mínima (debe actualizar); `update_available`: hay una más nueva.
     *
     * @return array{min_version: string, latest_version: string, download_url: string, status: string}
     */
    public static function forSite(Site $site): array
    {
        $current = (string) $site->getAttribute('plugin_version');
        $status = 'current';
        if ($current !== '' && self::isVersion($current)) {
            if (version_compare($current, self::minVersion(), '<')) {
                $status = 'outdated';
            } elseif (version_compare($current, self::latestVersion(), '<')) {
                $status = 'update_available';
            }
        }

        return [
            'min_version' => self::minVersion(),
            'latest_version' => self::latestVersion(),
            'download_url' => self::downloadUrl(),
            'status' => $status,
        ];
    }

    private static function isVersion(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $v) === 1;
    }
}
