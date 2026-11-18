<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Enums;

/**
 * Clase legal del mensaje (cumplimiento.md §4.1). Solo `marketing` es comercial:
 * le aplican la ventana legal, la frecuencia y el consentimiento de marketing.
 * Lo ambiguo (recordatorio de pago pendiente, aviso de stock) es marketing por
 * defecto; un flujo lo puede reclasificar con una justificación que queda en la
 * auditoría. `utility` es un caso transaccional enviado con una plantilla de
 * utilidad de WhatsApp (confirmar contraentrega, envío del pedido).
 */
enum MessageKind: string
{
    case Marketing = 'marketing';
    case Utility = 'utility';               // plantilla de utilidad de WhatsApp (confirmar contraentrega, pago pendiente)
    case Transactional = 'transactional';   // confirmación de una transacción (art. 8 de la Ley 2300)
    case Service = 'service';               // respuesta dentro de una conversación iniciada por el contacto
    case Authentication = 'authentication'; // OTP

    /** ¿Le aplican la ventana legal, la frecuencia y el consentimiento de marketing? */
    public function isCommercial(): bool
    {
        return $this === self::Marketing;
    }
}
