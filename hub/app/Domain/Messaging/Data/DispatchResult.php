<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Data;

use Carbon\CarbonImmutable;

/**
 * Resultado de MessageDispatcher::dispatch().
 *
 * - `queued`: pasó la política y quedó en `messages:{canal}`.
 * - `deferred`: la política lo aplaza (ventana legal, festivo, frecuencia). **No se
 *   crea el mensaje**: quien llamó vuelve a llamar con la misma clave en `until`
 *   (el flujo devuelve Deferred(until); la campaña reprograma al destinatario).
 * - `denied`: no se envía (sin consentimiento, suprimido, sin canal, cuenta pausada…).
 * - `duplicate`: esa clave de idempotencia ya se envió; `messageId` es el original.
 */
final class DispatchResult
{
    public const QUEUED = 'queued';

    public const DEFERRED = 'deferred';

    public const DENIED = 'denied';

    public const DUPLICATE = 'duplicate';

    private function __construct(
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?CarbonImmutable $until = null,
        public readonly ?string $reason = null,
    ) {}

    public static function queued(string $messageId): self
    {
        return new self(self::QUEUED, $messageId);
    }

    public static function deferred(CarbonImmutable $until, string $reason): self
    {
        return new self(self::DEFERRED, null, $until, $reason);
    }

    public static function denied(string $reason): self
    {
        return new self(self::DENIED, null, null, $reason);
    }

    public static function duplicate(string $messageId): self
    {
        return new self(self::DUPLICATE, $messageId);
    }

    public function sent(): bool
    {
        return $this->status === self::QUEUED || $this->status === self::DUPLICATE;
    }
}
