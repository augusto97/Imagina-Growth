<?php

declare(strict_types=1);

namespace App\Support\Net;

/**
 * ¿Una IP es de Internet pública? (S5, SSRF). Lista explícita de rangos de IPv4 e IPv6 que no son públicos:
 * privados, locales, link-local, CGNAT, documentación, multicast y reservados. Las IPv6 que llevan una IPv4 adentro
 * (mapeadas `::ffff:a.b.c.d`, compatibles `::a.b.c.d` y NAT64 `64:ff9b::/96`) se juzgan por esa IPv4; 6to4 y Teredo
 * se bloquean. No depende de las banderas de `filter_var`, que cambian entre versiones de PHP.
 */
final class IpAddress
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // «esta red»
        '10.0.0.0/8',         // privada
        '100.64.0.0/10',      // CGNAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local (metadatos de la nube: 169.254.169.254)
        '172.16.0.0/12',      // privada
        '192.0.0.0/24',       // asignaciones del IETF
        '192.0.2.0/24',       // documentación
        '192.88.99.0/24',     // relé 6to4
        '192.168.0.0/16',     // privada
        '198.18.0.0/15',      // pruebas de rendimiento
        '198.51.100.0/24',    // documentación
        '203.0.113.0/24',     // documentación
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reservada y difusión
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128',             // sin especificar
        '::1/128',            // loopback
        '100::/64',           // descarte
        '2001::/23',          // asignaciones del IETF (Teredo incluido)
        '2001:db8::/32',      // documentación
        '2002::/16',          // 6to4
        'fc00::/7',           // locales únicas (ULA)
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (obsoleta)
        'ff00::/8',           // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[] ');
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            return ! self::inAny($bin, self::BLOCKED_V4);
        }
        $embedded = self::embeddedV4($bin);
        if ($embedded !== null) {
            return ! self::inAny($embedded, self::BLOCKED_V4);
        }

        return ! self::inAny($bin, self::BLOCKED_V6);
    }

    public static function isIp(string $value): bool
    {
        return @inet_pton(trim($value, '[]')) !== false;
    }

    /** La IPv4 dentro de una IPv6 mapeada, compatible o NAT64, o null. */
    private static function embeddedV4(string $bin): ?string
    {
        $prefix = substr($bin, 0, 12);
        $mapped = str_repeat("\0", 10)."\xff\xff";
        $compatible = str_repeat("\0", 12);
        $nat64 = "\x00\x64\xff\x9b".str_repeat("\0", 8);
        if ($prefix === $mapped || $prefix === $nat64 || ($prefix === $compatible && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1")) {
            return substr($bin, 12);
        }

        return null;
    }

    /** @param list<string> $cidrs */
    private static function inAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = (string) inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
