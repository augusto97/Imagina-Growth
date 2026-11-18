<?php

declare(strict_types=1);

namespace App\Support\Hmac;

/**
 * Firma de docs/api-connector.md §2:
 *   X-IGR-Signature: t=<unix>,v1=hex(hmac_sha256(secret, t + "." + cuerpo))
 * El cuerpo son los bytes exactos que viajan (comprimidos si van con gzip).
 */
final class Signature
{
    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
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
            if ($secret !== '' && hash_equals(hash_hmac('sha256', $parsed['t'].'.'.$body, $secret), $parsed['v1'])) {
                return true;
            }
        }

        return false;
    }
}
