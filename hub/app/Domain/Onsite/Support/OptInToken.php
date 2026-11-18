<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Messaging\Support\ContactLinkToken;

/**
 * Token del enlace de doble opt-in por correo (`/p/{clave}/confirmar/{token}`):
 * espacio de trabajo, contacto, widget y vencimiento (7 días), firmado con la clave
 * de la app. No lleva datos personales.
 */
final class OptInToken
{
    public const TTL_DAYS = 7;

    public static function make(string $workspaceId, string $contactId, string $widgetId, ?int $now = null): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'w' => $workspaceId, 'c' => $contactId, 'g' => $widgetId, 'e' => ($now ?? time()) + self::TTL_DAYS * 86400,
        ])), '+/', '-_'), '=');

        return $payload.'.'.substr(self::sign($payload), 0, 32);
    }

    /** @return array{w: string, c: string, g: string}|null */
    public static function parse(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(substr(self::sign($parts[0]), 0, 32), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ! is_string($data['w'] ?? null) || ! is_string($data['c'] ?? null) || ! is_string($data['g'] ?? null) || (int) ($data['e'] ?? 0) < ($now ?? time())) {
            return null;
        }

        return ['w' => $data['w'], 'c' => $data['c'], 'g' => $data['g']];
    }

    public static function url(string $publicKey, string $workspaceId, string $contactId, string $widgetId): string
    {
        return ContactLinkToken::base().'/p/'.$publicKey.'/confirmar/'.self::make($workspaceId, $contactId, $widgetId);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'igr-optin|'.$payload, (string) config('app.key'));
    }
}
