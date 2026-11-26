<?php

declare(strict_types=1);

namespace App\Domain\Capture;

use App\Domain\Capture\Listeners\WhatsAppLinkInbound;
use App\Domain\Inbox\Events\InboundMessageReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Captación sin tienda (rediseño v2 §11.3, modulos.md M12 y M18): páginas de captura alojadas en el hub (`/p/{slug}`),
 * QR para imprimir, chats de WhatsApp con atribución (`/p/w/{código}`) y leads de anuncios (Meta Lead Ads y
 * formularios de Google Ads). Rutas en routes/api/capture.php y routes/public/capture.php.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(InboundMessageReceived::class, WhatsAppLinkInbound::class);
    }
}
