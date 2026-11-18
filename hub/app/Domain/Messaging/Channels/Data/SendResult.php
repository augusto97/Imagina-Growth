<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

/**
 * Respuesta del proveedor. `retryable` = error transitorio (5xx, red, límite
 * de velocidad): el job reintenta. Si no, el mensaje queda fallido.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
    ) {}

    public static function sent(?string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $error, ?string $code = null, bool $retryable = false): self
    {
        return new self(false, null, mb_substr($error, 0, 500), $code, $retryable);
    }
}
