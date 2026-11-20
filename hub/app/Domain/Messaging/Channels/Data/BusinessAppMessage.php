<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

use Carbon\CarbonImmutable;

/**
 * Un mensaje de un número en coexistencia (la Cloud API y la app de WhatsApp Business del celular a la vez): lo que el
 * negocio escribió desde la app (`smb_message_echoes`) o un mensaje del historial copiado al conectar (`history`).
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/smb_message_echoes
 *
 * `phone` puede faltar (Meta solo manda el BSUID, `userId`, si la persona adoptó un nombre de usuario). `text` solo
 * vive en memoria hasta guardarse cifrado en `messages.payload`.
 */
final class BusinessAppMessage
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly bool $outbound,                   // true: lo escribió el negocio; false: el cliente (solo historial)
        public readonly ?string $phone,                   // teléfono del cliente, solo dígitos
        public readonly ?string $userId,                  // BSUID del cliente
        public readonly string $type,                     // text | image | video | document | media_placeholder | edit | revoke | …
        public readonly CarbonImmutable $at,
        public readonly ?string $text = null,
        public readonly ?string $mediaId = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $targetMessageId = null,  // edit y revoke: el mensaje original
        public readonly ?string $profileName = null,
    ) {}
}
