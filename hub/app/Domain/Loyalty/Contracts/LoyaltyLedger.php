<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Contracts;

/**
 * Libro de puntos y saldo a favor (M13, `loyalty_ledger`, solo inserción). Lo
 * usan los pedidos, las reseñas (M15), los referidos (M14), los widgets (M12) y
 * los nodos «dar puntos» y «dar saldo» de los flujos. Todo es idempotente por
 * `idempotencyKey`: acreditar dos veces lo mismo devuelve la entrada original.
 * Debe correr dentro del contexto del espacio de trabajo.
 *
 * Sin programa activo, `earnPoints` devuelve null (no hay puntos que dar); el
 * saldo funciona aunque el programa de puntos esté apagado.
 */
interface LoyaltyLedger
{
    public function isActive(): bool;

    /**
     * Puntos fijos que el programa da por ese motivo (`signup`, `birthday`,
     * `review`, `review_media`, `profile`); 0 si está apagado o no hay programa
     * activo. Los de referidos los define M14 y los pasa en `earnPoints`. `earnPoints` con `points <= 0` usa este valor.
     */
    public function rulePoints(string $reason): int;

    /**
     * Acredita puntos. Con `pending`, quedan pendientes hasta `settle()` (pedidos:
     * hasta que se completa y pasa el periodo de devolución). Con `points <= 0`
     * se usa la regla del programa para `reason` (rulePoints); si da 0, no hay
     * puntos y devuelve null.
     *
     * @return string|null ID de la entrada (`lg_…`), o null si no hay programa activo
     */
    public function earnPoints(string $contactId, int $points, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null, bool $pending = false): ?string;

    /** Confirma o revierte los puntos pendientes de una referencia (p. ej. order/1234). */
    public function settle(string $refType, string $refId, bool $confirm): void;

    /** @return string ID de la entrada (`lg_…`) */
    public function creditWallet(string $contactId, string $amount, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null): string;

    public function pointsBalance(string $contactId): int;

    public function walletBalance(string $contactId): string;
}
