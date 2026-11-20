<?php

declare(strict_types=1);

namespace App\Domain\Connections\Enums;

/**
 * Proveedores de conexiones con un clic (docs/auditoria-2026-11.md §6):
 * - `meta`: Facebook Login for Business (anuncios, conjunto de datos o píxel, páginas e Instagram).
 * - `meta_whatsapp`: Embedded Signup de WhatsApp (una conexión por cuenta de WhatsApp Business).
 * - `google`: un solo OAuth de la plataforma para Google Ads (Data Manager), Analytics y Calendar.
 * - `tiktok`: TikTok for Business (Events API, audiencias y gasto).
 */
enum ConnectionProvider: string
{
    case Meta = 'meta';
    case MetaWhatsApp = 'meta_whatsapp';
    case Google = 'google';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta',
            self::MetaWhatsApp => 'WhatsApp',
            self::Google => 'Google',
            self::TikTok => 'TikTok',
        };
    }

    /** Si se conecta con la redirección de OAuth ({APP_URL}/oauth/{proveedor}/callback). WhatsApp usa la ventana de Meta. */
    public function usesRedirect(): bool
    {
        return $this !== self::MetaWhatsApp;
    }

    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/oauth/'.$this->value.'/callback';
    }
}
