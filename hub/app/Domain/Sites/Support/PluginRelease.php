<?php

declare(strict_types=1);

namespace App\Domain\Sites\Support;

use App\Domain\Sites\Models\Site;

/**
 * Versiones del plugin que conoce el hub (connector/CLAUDE.md §15, api-connector.md §5.1):
 * la mínima compatible y la que trae este paquete en `public/downloads`. Viajan en
 * `/v1/s/config`, así el plugin avisa en el admin de WordPress sin pedir nada más al hub.
 */
final class PluginRelease
{
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
