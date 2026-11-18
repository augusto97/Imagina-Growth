<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

/**
 * Enlace mágico del panel del creador (`/creadores/{token}`): espacio de trabajo,
 * afiliado, nonce y vencimiento, firmados con la clave de la app. Cambiar el nonce
 * del afiliado (enviar un enlace nuevo) invalida los anteriores. Sin datos personales.
 */
final class CreatorToken
{
    public const TTL_DAYS = 30;

    public static function make(string $workspaceId, string $affiliateId, string $nonce, ?int $expiresAt = null): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'w' => $workspaceId, 'a' => $affiliateId, 'n' => $nonce, 'e' => $expiresAt ?? now()->addDays(self::TTL_DAYS)->getTimestamp(),
        ])), '+/', '-_'), '=');

        return $payload.'.'.substr(self::sign($payload), 0, 40);
    }

    /**
     * El contenido si la firma es válida (aunque esté vencido: así se puede pedir uno nuevo).
     *
     * @return array{w: string, a: string, n: string, e: int, expired: bool}|null
     */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($parts[1]) !== 40 || ! hash_equals(substr(self::sign($parts[0]), 0, 40), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ! is_string($data['w'] ?? null) || ! is_string($data['a'] ?? null) || ! is_string($data['n'] ?? null) || ! is_int($data['e'] ?? null)) {
            return null;
        }

        return ['w' => $data['w'], 'a' => $data['a'], 'n' => $data['n'], 'e' => $data['e'], 'expired' => $data['e'] < now()->getTimestamp()];
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('igr.urls.app'), '/').'/creadores/'.$token;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'igr-creator|'.$payload, (string) config('app.key'));
    }
}
