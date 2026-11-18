<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

/**
 * Token de los enlaces de baja (/u/{token}) y del centro de preferencias
 * (/preferencias/{token}): espacio de trabajo, contacto, canal y mensaje,
 * firmados con la clave de la app. No vence: la baja debe funcionar siempre.
 * No lleva datos personales.
 */
final class ContactLinkToken
{
    public static function make(string $workspaceId, string $contactId, string $channel, ?string $messageId = null): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(array_filter([
            'w' => $workspaceId, 'c' => $contactId, 'ch' => $channel, 'm' => $messageId,
        ]))), '+/', '-_'), '=');

        return $payload.'.'.substr(self::sign($payload), 0, 32);
    }

    /** @return array{w: string, c: string, ch: string, m: string|null}|null */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(substr(self::sign($parts[0]), 0, 32), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($data) || ! is_string($data['w'] ?? null) || ! is_string($data['c'] ?? null) || ! is_string($data['ch'] ?? null)) {
            return null;
        }

        return ['w' => $data['w'], 'c' => $data['c'], 'ch' => $data['ch'], 'm' => is_string($data['m'] ?? null) ? $data['m'] : null];
    }

    public static function unsubscribeUrl(string $workspaceId, string $contactId, string $channel, ?string $messageId = null): string
    {
        return self::base().'/u/'.self::make($workspaceId, $contactId, $channel, $messageId);
    }

    public static function preferencesUrl(string $workspaceId, string $contactId, string $channel, ?string $messageId = null): string
    {
        return self::base().'/preferencias/'.self::make($workspaceId, $contactId, $channel, $messageId);
    }

    public static function base(): string
    {
        return rtrim((string) config('igr-messaging.links_url'), '/');
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'igr-contact-link|'.$payload, (string) config('app.key'));
    }
}
