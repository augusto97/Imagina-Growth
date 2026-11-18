<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

use Carbon\CarbonImmutable;

/**
 * Cambio de estado de un mensaje que informa el proveedor: sent, delivered,
 * read, opened, failed, bounced o complained.
 */
final class StatusUpdate
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $status,
        public readonly CarbonImmutable $at,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $bounceType = null,  // hard | soft
        public readonly bool $isMpp = false,          // apertura automática de Apple Mail
        public readonly ?string $recipient = null,    // correo o teléfono afectado (rebotes y quejas)
    ) {}
}
