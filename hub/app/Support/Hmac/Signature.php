<?php

declare(strict_types=1);

namespace App\Support\Hmac;

/**
 * Firma v1 de docs/api-connector.md §2:
 *   X-IGR-Signature: t=<unix>,v1=hex(hmac_sha256(secret, t + "." + cuerpo))
 * El cuerpo son los bytes exactos que viajan (comprimidos si van con gzip).
 *
 * La usan los webhooks salientes (docs/api-publica.md §9.3 y el nodo webhook de los flujos). Entre el plugin y el
 * hub manda RequestSignature (v2, que además cubre el método, la ruta, la consulta y un nonce; v1 solo por
 * compatibilidad con plugins anteriores a la 0.1.4).
 */
final class Signature
{
    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.self::digest($secret, $t, $body);
    }

    /** hex(HMAC-SHA256(secreto, t + "." + cuerpo)) */
    public static function digest(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array{t: int, v1: string}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }
        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t']) || ! preg_match('/^[0-9a-f]{64}$/', $parts['v1'])) {
            return null;
        }

        return ['t' => (int) $parts['t'], 'v1' => $parts['v1']];
    }

    /**
     * @param  list<string>  $secrets  secretos válidos (el actual y, durante la rotación, el anterior)
     */
    public static function verify(?string $header, string $body, array $secrets, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $parsed = self::parse($header);
        if ($parsed === null) {
            return false;
        }
        if (abs(($now ?? time()) - $parsed['t']) > $toleranceSeconds) {
            return false;
        }
        foreach ($secrets as $secret) {
            if ($secret !== '' && hash_equals(self::digest($secret, $parsed['t'], $body), $parsed['v1'])) {
                return true;
            }
        }

        return false;
    }
}
