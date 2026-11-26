<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Capture\Models\WhatsAppLink;

/**
 * Códigos de atribución de los chats de WhatsApp: 7 caracteres sin ambigüedades (sin 0/O, 1/I/L, 2/Z, 5/S, 8/B), que
 * empiezan por `Q` (QR impreso) o `P` (botón de una página). El tracker usa tokens de 5 (`ref: K7Q2M`), así que un
 * código de estos nunca se confunde con uno del sitio. `InboundHandler::waRef` los lee del mensaje.
 */
final class WaCodes
{
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    public static function generate(string $kind): string
    {
        $prefix = $kind === 'page' ? 'P' : 'Q';
        for ($i = 0; $i < 20; $i++) {
            $code = $prefix;
            for ($j = 0; $j < 6; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (! WhatsAppLink::withoutWorkspaceScope()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('No se pudo generar un código de atribución libre.');
    }
}
