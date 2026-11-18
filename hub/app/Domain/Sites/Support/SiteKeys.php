<?php

declare(strict_types=1);

namespace App\Domain\Sites\Support;

/** Generación de claves y códigos (api-connector.md §1). */
final class SiteKeys
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function publicKey(): string
    {
        return 'pk_'.strtolower(self::base32(random_bytes(20), 32));
    }

    public static function secret(): string
    {
        return 'sk_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function connectCode(): string
    {
        $chars = self::base32(random_bytes(8), 12);

        return 'IGR-'.substr($chars, 0, 4).'-'.substr($chars, 4, 4).'-'.substr($chars, 8, 4);
    }

    /** Normaliza lo que pega la persona: mayúsculas, sin espacios, con guiones. */
    public static function normalizeCode(string $input): ?string
    {
        $raw = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (str_starts_with($raw, 'IGR') && strlen($raw) === 15) {
            $raw = substr($raw, 3);
        }
        $raw = strtr($raw, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($raw) !== 12 || strspn($raw, self::CROCKFORD) !== 12) {
            return null;
        }

        return 'IGR-'.substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 4);
    }

    public static function hashCode(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    /** Host normalizado para comparar URLs de sitios (sin www ni puerto). */
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function base32(string $bytes, int $length): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split(substr($bits, 0, $length * 5), 5) as $chunk) {
            $out .= self::CROCKFORD[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }
}
