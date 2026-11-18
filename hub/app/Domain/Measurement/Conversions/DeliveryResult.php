<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

/** Resultado de un intento de entrega a una plataforma. */
final class DeliveryResult
{
    public const DELIVERED = 'delivered';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly int $httpStatus = 0,
        public readonly string $errorCode = '',
        public readonly string $message = '',
    ) {}

    public static function delivered(int $http = 200): self
    {
        return new self(self::DELIVERED, $http);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::DELIVERED;
    }
}
