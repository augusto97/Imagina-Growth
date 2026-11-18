<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Support;

use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use RuntimeException;

/** Hasta que M13 registre el libro real: no hay programa, no hay puntos. */
final class NullLoyaltyLedger implements LoyaltyLedger
{
    public function isActive(): bool
    {
        return false;
    }

    public function earnPoints(string $contactId, int $points, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null, bool $pending = false): ?string
    {
        return null;
    }

    public function settle(string $refType, string $refId, bool $confirm): void {}

    public function creditWallet(string $contactId, string $amount, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null): string
    {
        throw new RuntimeException(__('El saldo a favor no está disponible.'));
    }

    public function pointsBalance(string $contactId): int
    {
        return 0;
    }

    public function walletBalance(string $contactId): string
    {
        return '0.00';
    }
}
