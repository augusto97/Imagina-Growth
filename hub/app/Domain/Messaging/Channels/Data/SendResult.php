<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Channels\Data;

use Carbon\CarbonImmutable;

/**
 * Respuesta del proveedor. `retryable` = error transitorio (5xx, red, límite
 * de velocidad): el job reintenta. `retryAt` = el proveedor dijo hasta cuándo
 * no se puede (p. ej. el tope diario de calentamiento de Imagina Whats): el
 * mensaje queda aplazado hasta esa hora, sin gastar intentos. Si no, falla.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly bool $retryable = false,
        public readonly ?CarbonImmutable $retryAt = null,
    ) {}

    public static function sent(?string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $error, ?string $code = null, bool $retryable = false): self
    {
        return new self(false, null, mb_substr($error, 0, 500), $code, $retryable);
    }

    /** El proveedor no acepta envíos hasta `$until`: el mensaje se aplaza, no falla. */
    public static function later(CarbonImmutable $until, string $error, ?string $code = null): self
    {
        return new self(false, null, mb_substr($error, 0, 500), $code, true, $until);
    }
}
