<?php

declare(strict_types=1);

namespace App\Support\Net;

/**
 * URL y hosts de salida que eligen los clientes (S5, SSRF): webhooks, nodos de webhook de los flujos, `rest_url`
 * del sitio, Zender, el host SMTP y las páginas que lee el tono de marca. Nada de localhost, redes privadas,
 * link-local ni sus equivalentes en IPv6.
 *
 * Dos momentos: `problem()` al guardar (mensaje para la persona) y `pin()` al conectar, que resuelve el DNS en ese
 * instante, exige que **todas** las IP sean públicas y devuelve la IP a la que se debe conectar (así un cambio de DNS
 * entre la revisión y la conexión, el DNS rebinding, no lleva a una red interna). `SafeHttp` y `SmtpDriver` conectan
 * a esa IP. En local y en pruebas (`igr.webhooks.allow_private_urls`) se permite todo para poder probar con
 * servicios locales.
 */
final class SafeUrl
{
    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain', '.home.arpa', '.lan'];

    /** Error para mostrar o null si la URL sirve. */
    public static function problem(string $url, bool $httpsOnly = true): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return __('La URL no es válida.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($httpsOnly && $scheme !== 'https' && ! self::allowsPrivate()) {
            return __('La URL debe empezar por https://.');
        }
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return __('La URL no es válida.');
        }

        return self::hostProblem((string) ($parts['host'] ?? ''));
    }

    /** Error para mostrar si el host (nombre o IP) no es público, o null. */
    public static function hostProblem(string $host): ?string
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return __('La URL no es válida.');
        }
        if (self::allowsPrivate()) {
            return null;
        }
        if (self::blockedName($host) || (IpAddress::isIp($host) && ! IpAddress::isPublic($host))) {
            return __('La dirección debe ser pública: no se aceptan servidores locales ni de redes internas.');
        }

        return null;
    }

    /**
     * Resuelve el host ahora y devuelve a qué IP conectar. Lanza UnsafeUrl si el nombre no resuelve o si alguna de
     * sus IP no es pública.
     */
    public static function pin(string $host, int $port): PinnedHost
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            throw new UnsafeUrl('invalid_url');
        }
        if (self::allowsPrivate()) {
            return new PinnedHost($host, $port, null);
        }
        if (self::blockedName($host)) {
            throw new UnsafeUrl('url_not_public');
        }
        $ips = IpAddress::isIp($host) ? [$host] : app(HostResolver::class)->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrl('dns_unresolved');
        }
        foreach ($ips as $ip) {
            if (! IpAddress::isPublic($ip)) {
                throw new UnsafeUrl('url_not_public');
            }
        }

        return new PinnedHost($host, $port, $ips[0]);
    }

    /** Al enviar: el nombre debe resolver solo a IP públicas. */
    public static function resolvesPublic(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }
        try {
            self::pin((string) $parts['host'], self::port($parts));

            return true;
        } catch (UnsafeUrl) {
            return false;
        }
    }

    /** @param array<string, mixed> $parts lo que devuelve parse_url */
    public static function port(array $parts): int
    {
        return isset($parts['port']) ? (int) $parts['port'] : (strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 80 : 443);
    }

    public static function allowsPrivate(): bool
    {
        return (bool) config('igr.webhooks.allow_private_urls', app()->environment('local', 'testing'));
    }

    private static function normalizeHost(string $host): string
    {
        return rtrim(strtolower(trim($host, '[] ')), '.');
    }

    private static function blockedName(string $host): bool
    {
        if ($host === 'localhost' || (! str_contains($host, '.') && ! IpAddress::isIp($host))) {
            return true; // localhost y nombres sin dominio (resueltos por la red interna)
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
