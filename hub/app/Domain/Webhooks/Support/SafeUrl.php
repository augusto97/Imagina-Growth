<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

/**
 * Las URL de webhooks deben ser HTTPS y públicas: nada de localhost ni redes privadas
 * (evita que un webhook sirva para tocar servicios internos del hub).
 */
final class SafeUrl
{
    /** Error para mostrar o null si la URL sirve. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' && ! self::allowsPrivate()) {
            return __('La URL debe empezar por https://.');
        }
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return __('La URL no es válida.');
        }
        if (self::allowsPrivate()) {
            return null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return __('La URL debe ser pública.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! self::isPublicIp($host)) {
            return __('La URL debe ser pública.');
        }

        return null;
    }

    /** Al enviar: el nombre debe resolver solo a IP públicas. */
    public static function resolvesPublic(string $url): bool
    {
        if (self::allowsPrivate()) {
            return true;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function allowsPrivate(): bool
    {
        return (bool) config('igr.webhooks.allow_private_urls', app()->environment('local', 'testing'));
    }
}
