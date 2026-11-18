<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Ledger;

use App\Domain\Loyalty\Models\LedgerEntry;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Saldos de un contacto a partir de su libro (ver DatabaseLoyaltyLedger):
 *
 * - Puntos disponibles: suma de `confirmed` y `expired` (los vencidos restan).
 * - Puntos pendientes: cada entrada `pending` sin confirmar, menos sus
 *   reversiones (`reversed`, hijas por `parent_id`).
 * - Saldo disponible: suma de `confirmed` de la billetera, menos los débitos
 *   pendientes (un cupón de saldo emitido y aún sin usar ya no se puede volver
 *   a gastar).
 * - Por vencer: los puntos ganados se consumen del más viejo al más nuevo
 *   (FIFO); lo que queda de cada abono vence en su `expires_at`. `expiring_at`
 *   es la fecha más próxima (puede estar en el pasado si el job nocturno aún
 *   no los venció) y `expiring`, los puntos que vencen ese día.
 */
final class Balances
{
    /**
     * @return array{points: int, pending: int, wallet: string, expiring: int, expiring_at: string|null, expired_due: list<array{entry: LedgerEntry, remaining: int}>}
     */
    public function compute(string $contactId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        /** @var Collection<int, LedgerEntry> $rows */
        $rows = LedgerEntry::query()->where('contact_id', $contactId)->orderBy('created_at')->orderBy('id')
            ->get(['id', 'kind', 'delta', 'status', 'parent_id', 'expires_at', 'created_at']);

        // Las hijas de una entrada pendiente pueden estar en otro contacto (se fusionó y se confirmó en el que quedó).
        $pendingIds = $rows->where('status', 'pending')->pluck('id')->all();
        $children = [];
        $related = $pendingIds === [] ? collect() : LedgerEntry::query()->whereIn('parent_id', $pendingIds)->where('contact_id', '!=', $contactId)->get(['id', 'kind', 'delta', 'status', 'parent_id']);
        foreach ($rows->concat($related) as $row) {
            if ($row->parent_id !== null) {
                $children[$row->parent_id][] = $row;
            }
        }

        $points = 0;
        $pending = 0;
        $wallet = '0.00';
        foreach ($rows as $row) {
            if ($row->kind === 'points') {
                if (in_array($row->status, ['confirmed', 'expired'], true)) {
                    $points += $row->points();
                } elseif ($row->status === 'pending') {
                    $pending += (int) round((float) self::pendingRemaining($row, $children[$row->id] ?? []));
                }

                continue;
            }
            if ($row->status === 'confirmed') {
                $wallet = Decimal::add($wallet, (string) $row->delta);
            } elseif ($row->status === 'pending' && (float) $row->delta < 0) {
                $wallet = Decimal::add($wallet, self::pendingRemaining($row, $children[$row->id] ?? []));
            }
        }

        // FIFO: los débitos (canjes, vencimientos, reembolsos) consumen los abonos más viejos.
        $consumed = 0;
        foreach ($rows as $row) {
            if ($row->kind === 'points' && in_array($row->status, ['confirmed', 'expired'], true) && $row->points() < 0) {
                $consumed -= $row->points();
            }
        }
        $expiring = 0;
        $expiringAt = null;
        $due = [];
        foreach ($rows as $row) {
            if ($row->kind !== 'points' || $row->status !== 'confirmed' || $row->points() <= 0) {
                continue;
            }
            $take = min($consumed, $row->points());
            $consumed -= $take;
            $remaining = $row->points() - $take;
            if ($remaining <= 0 || $row->expires_at === null) {
                continue;
            }
            if ($row->expires_at->lte($now)) {
                // Vencidos y aún sin procesar: siguen contando como «por vencer» (fecha pasada) hasta el job nocturno.
                $due[] = ['entry' => $row, 'remaining' => $remaining];
            }
            $date = $row->expires_at->toDateString();
            if ($expiringAt === null || $date < $expiringAt) {
                $expiringAt = $date;
                $expiring = $remaining;
            } elseif ($date === $expiringAt) {
                $expiring += $remaining;
            }
        }

        return ['points' => $points, 'pending' => max(0, $pending), 'wallet' => $wallet, 'expiring' => $expiring, 'expiring_at' => $expiringAt, 'expired_due' => $due];
    }

    /**
     * Lo que sigue pendiente de una entrada `pending`: su monto menos las
     * reversiones; cero si ya se confirmó.
     *
     * @param  array<int, LedgerEntry>  $children
     */
    public static function pendingRemaining(LedgerEntry $pending, array $children): string
    {
        $remaining = (string) $pending->delta;
        foreach ($children as $child) {
            if ($child->status === 'confirmed') {
                return '0.00';
            }
            if ($child->status === 'reversed') {
                $remaining = Decimal::add($remaining, (string) $child->delta);
            }
        }

        return $remaining;
    }
}
