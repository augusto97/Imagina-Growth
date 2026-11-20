<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use App\Domain\Workspaces\Models\Workspace;

/**
 * Captura temprana del checkout (eventos.md §4.2, cumplimiento.md §3.2): el plugin
 * envía `checkout.contact_captured` firmado cuando un invitado escribe un correo o
 * un teléfono válido en el checkout, para recuperar ese carrito si no compra.
 *
 * - Apagada por defecto. Se enciende en Consentimiento aceptando la responsabilidad
 *   (queda en la auditoría) y el plugin muestra el aviso junto al campo.
 * - El hub también la revisa: con la opción apagada el evento se rechaza
 *   (`not_allowed`) y no crea ni toca contactos, aunque el plugin aún tenga la
 *   configuración vieja en caché.
 * - No crea consentimiento de marketing: el envío sigue pasando por SendPolicy.
 * - Un contacto que existe solo por la captura queda marcado (`early_capture_at`):
 *   no entra a las audiencias de anuncios ni suma puntos hasta que compra, se
 *   registra o deja un formulario.
 */
final class EarlyCapture
{
    public const EVENT = 'checkout.contact_captured';

    /** Primera versión del plugin que la envía (contrato v6). */
    public const MIN_PLUGIN_VERSION = '0.1.3';

    /** Eventos con los que la persona deja de ser «solo captura temprana». */
    public const CLEARING_PREFIXES = ['order.', 'customer.'];

    public const CLEARING_EVENTS = ['form.submitted'];

    public static function enabled(Workspace $workspace): bool
    {
        return (bool) $workspace->setting('early_capture', false);
    }

    public static function clears(string $event): bool
    {
        if (in_array($event, self::CLEARING_EVENTS, true)) {
            return true;
        }
        foreach (self::CLEARING_PREFIXES as $prefix) {
            if (str_starts_with($event, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
