<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Sites\Models\Site;

/**
 * Tokens firmados por el sitio para el navegador (api-connector.md §7.1):
 * `base64url(JSON)` "." `hex(HMAC-SHA256(secreto, payload))`. Se verifican con
 * el secreto actual o, durante la rotación, con el anterior.
 */
final class SiteToken
{
    /**
     * @return array<string, mixed>|null el payload si la firma, el sitio y el vencimiento son válidos
     */
    public static function verify(Site $site, ?string $token, ?int $now = null): ?array
    {
        if ($token === null || ! preg_match('/^([A-Za-z0-9_-]+)\.([0-9a-f]{64})$/', $token, $m)) {
            return null;
        }
        [, $payload, $signature] = $m;
        $valid = false;
        foreach ($site->verificationSecrets() as $secret) {
            if ($secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || ($data['site'] ?? null) !== $site->publicId() || ! is_int($data['exp'] ?? null) || $data['exp'] < ($now ?? time())) {
            return null;
        }

        return $data;
    }

    /**
     * Firma un payload (lo usan las pruebas y, en el plugin, su equivalente en PHP).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(string $secret, array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, $secret);
    }
}
