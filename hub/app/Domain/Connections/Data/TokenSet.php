<?php

declare(strict_types=1);

namespace App\Domain\Connections\Data;

use Carbon\CarbonImmutable;

/** Lo que devuelve un proveedor al canjear el código o renovar el token. Nunca se escribe en los logs. */
final readonly class TokenSet
{
    /**
     * @param  list<string>  $scopes  alcances concedidos (la persona puede negar algunos)
     * @param  array<string, mixed>  $assets  cuentas que autorizó (IDs y nombres, sin secretos)
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $scopes = [],
        public ?string $externalId = null,
        public ?string $accountName = null,
        public array $assets = [],
    ) {}
}
