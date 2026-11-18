<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

use Carbon\CarbonImmutable;

/**
 * Mensaje entrante de WhatsApp o SMS. `text` solo se usa en memoria (palabras de
 * baja, token `wa_ref`): nunca se guarda en ClickHouse.
 */
final class InboundMessage
{
    public function __construct(
        public readonly string $from,                  // E.164
        public readonly string $providerMessageId,
        public readonly string $type,                  // text | button | interactive | image | …
        public readonly CarbonImmutable $at,
        public readonly ?string $text = null,
        public readonly ?string $buttonPayload = null,
        public readonly ?string $contextMessageId = null, // el mensaje al que responde (wamid)
        public readonly ?string $ctwaClid = null,
        public readonly ?string $referralSourceType = null,
        public readonly ?string $referralSourceId = null,
        public readonly ?string $profileName = null,
        public readonly ?string $recipientId = null,   // phone_number_id o número que recibió
        public readonly ?string $mediaId = null,       // Fase 4: id del adjunto en el proveedor (imagen, audio, video, documento, sticker)
        public readonly ?string $mediaMime = null,
        public readonly ?string $caption = null,       // texto que acompaña al adjunto
    ) {}
}
