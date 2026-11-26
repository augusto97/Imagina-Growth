<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\ImaginaWhats;

/**
 * Firma de los webhooks de Imagina Whats: `X-Webhook-Signature: t=<unix>,v1=<hex>`, con
 * v1 = HMAC-SHA256(secreto, t + "." + cuerpo crudo). Se rechaza una `t` con más de 5 minutos de diferencia.
 */
final class ImaginaWhatsSignature
{
    public const TOLERANCE = 300;

    public static function valid(string $rawBody, string $header, string $secret, ?int $now = null): bool
    {
        if (preg_match('/\At=(\d+),v1=([a-f0-9]{64})\z/', trim($header), $m) !== 1) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $m[1]) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$rawBody, $secret), $m[2]);
    }

    /** La cabecera que mandaría Imagina Whats (para las pruebas). */
    public static function sign(string $rawBody, string $secret, ?int $at = null): string
    {
        $t = (string) ($at ?? time());

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$rawBody, $secret);
    }
}
