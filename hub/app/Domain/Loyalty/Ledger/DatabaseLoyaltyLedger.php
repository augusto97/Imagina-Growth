<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Ledger;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Ingest\HubEvents;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Libro de puntos y saldo (M13). Solo inserción; cada escritura bloquea la
 * fila del contacto, calcula `balance_after` y deja al día las columnas del
 * contacto (`points_balance`, `wallet_balance_ws`, `points_expiring*`).
 *
 * Estados de una entrada:
 * - `confirmed`: cuenta en el saldo (abonos y débitos firmes).
 * - `pending`: aún no cuenta (puntos de un pedido en periodo de devolución; un
 *   débito de saldo con cupón emitido y sin usar, que sí reduce lo disponible).
 * - `reversed`: hija de una `pending` que le resta (reembolso o cancelación
 *   antes de confirmar, o saldo liberado). No toca el saldo confirmado.
 * - `expired`: puntos vencidos (resta).
 *
 * Confirmar una `pending` agrega una hija `confirmed` por lo que quede de ella
 * (clave `settle:{id}`). Un contacto fusionado escribe en el que quedó.
 */
final class DatabaseLoyaltyLedger implements LoyaltyLedger
{
    private ?LoyaltyProgram $program = null;

    private ?string $programWorkspace = null;

    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly HubEvents $events,
        private readonly Balances $balances,
    ) {}

    public function program(): ?LoyaltyProgram
    {
        $ws = $this->current->id();
        if ($this->programWorkspace !== $ws) {
            $this->program = LoyaltyProgram::query()->first();
            $this->programWorkspace = $ws;
        }

        return $this->program;
    }

    /** Olvida el programa leído (después de guardarlo). */
    public function forgetProgram(): void
    {
        $this->programWorkspace = null;
        $this->program = null;
    }

    public function isActive(): bool
    {
        return $this->program()?->isActive() ?? false;
    }

    public function rulePoints(string $reason): int
    {
        $program = $this->program();

        return $program !== null && $program->isActive() ? $program->rules()->fixedPoints($reason) : 0;
    }

    public function earnPoints(string $contactId, int $points, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null, bool $pending = false): ?string
    {
        $program = $this->program();
        if ($program === null || ! $program->isActive()) {
            return null;
        }
        if ($points <= 0) {
            $points = $program->rules()->fixedPoints($reason);
        }
        if ($points <= 0) {
            return null;
        }
        $expires = $pending ? null : $program->rules()->expiresAt(now());
        [$entry, $created] = $this->post($contactId, 'points', (string) $points, $pending ? 'pending' : 'confirmed', $reason, $idempotencyKey, $refType, $refId, expiresAt: $expires);
        if ($created) {
            $this->emit('points.earned', $entry, [
                'ledger_id' => $entry->publicId(), 'points' => $points, 'balance_after' => (int) round((float) $entry->balance_after),
                'status' => $entry->status, 'reason' => $reason, 'ref_type' => $refType, 'ref_id' => $refId,
                'expires_at' => $expires?->toIso8601String(),
            ]);
        }

        return $entry->id;
    }

    /** Abono manual del panel (también con el programa en pausa o en borrador). */
    public function manualPoints(string $contactId, int $points, string $reason, string $idempotencyKey): LedgerEntry
    {
        if ($points <= 0) {
            throw new InvalidArgumentException(__('Los puntos deben ser mayores que cero.'));
        }
        $expires = $this->program()?->rules()->expiresAt(now());
        [$entry, $created] = $this->post($contactId, 'points', (string) $points, 'confirmed', $reason, $idempotencyKey, 'manual', null, expiresAt: $expires);
        if ($created) {
            $this->emit('points.earned', $entry, [
                'ledger_id' => $entry->publicId(), 'points' => $points, 'balance_after' => (int) round((float) $entry->balance_after),
                'status' => 'confirmed', 'reason' => $reason, 'ref_type' => 'manual', 'expires_at' => $expires?->toIso8601String(),
            ]);
        }

        return $entry;
    }

    public function settle(string $refType, string $refId, bool $confirm): void
    {
        $pendings = LedgerEntry::query()->where('ref_type', $refType)->where('ref_id', $refId)->where('status', 'pending')->orderBy('created_at')->get();
        foreach ($pendings as $pending) {
            $children = LedgerEntry::query()->where('parent_id', $pending->id)->get()->all();
            $remaining = Balances::pendingRemaining($pending, $children);
            if (Decimal::equals($remaining, '0') || LedgerEntry::query()->where('idempotency_key', 'settle:'.$pending->id)->exists()) {
                continue;
            }
            if (! $confirm) {
                $this->post($pending->contact_id, $pending->kind, Decimal::sub('0', $remaining), 'reversed', $pending->reason, 'settle:'.$pending->id, $pending->ref_type, $pending->ref_id, $pending->id, currency: $pending->currency);

                continue;
            }
            $expires = $pending->kind === 'points' ? $this->program()?->rules()->expiresAt(now()) : null;
            [$entry, $created] = $this->post($pending->contact_id, $pending->kind, $remaining, 'confirmed', $pending->reason, 'settle:'.$pending->id, $pending->ref_type, $pending->ref_id, $pending->id, $expires, $pending->currency);
            if (! $created) {
                continue;
            }
            if ($pending->kind === 'points') {
                $this->emit('points.earned', $entry, [
                    'ledger_id' => $entry->publicId(), 'points' => $entry->points(), 'balance_after' => (int) round((float) $entry->balance_after),
                    'status' => 'confirmed', 'reason' => $entry->reason, 'ref_type' => $entry->ref_type, 'ref_id' => $entry->ref_id,
                    'expires_at' => $expires?->toIso8601String(),
                ]);
            } else {
                $this->emitWallet($entry);
            }
        }
    }

    public function creditWallet(string $contactId, string $amount, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null): string
    {
        $amount = Decimal::of($amount);
        if ((float) $amount <= 0) {
            throw new InvalidArgumentException(__('El monto del saldo debe ser mayor que cero.'));
        }
        [$entry, $created] = $this->post($contactId, 'wallet', $amount, 'confirmed', $reason, $idempotencyKey, $refType, $refId, currency: $this->current->get()->currency);
        if ($created) {
            $this->emitWallet($entry);
        }

        return $entry->id;
    }

    /**
     * Débito de saldo. Con `pending`, queda reservado (cupón emitido) hasta
     * settle(refType, refId); si no, es firme. Nunca deja el saldo en negativo:
     * lanza RuntimeException si no alcanza (salvo `allowPartial`, que debita lo
     * que haya).
     */
    public function debitWallet(string $contactId, string $amount, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null, bool $pending = false, bool $allowPartial = false): ?LedgerEntry
    {
        $amount = Decimal::of($amount);
        $contactId = $this->live($contactId);
        $existing = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $available = $this->balances->compute($contactId)['wallet'];
        if ($allowPartial && (float) $amount > (float) $available) {
            $amount = $available;
        }
        if ((float) $amount <= 0) {
            return null;
        }
        // Se vuelve a comprobar con el contacto bloqueado: dos canjes a la vez no gastan dos veces.
        $guard = function (array $before) use ($amount): void {
            if ((float) $amount > (float) $before['wallet'] + 1e-9) {
                throw new RuntimeException(__('El saldo no alcanza.'));
            }
        };
        [$entry, $created] = $this->post($contactId, 'wallet', Decimal::sub('0', $amount), $pending ? 'pending' : 'confirmed', $reason, $idempotencyKey, $refType, $refId, currency: $this->current->get()->currency, guard: $guard);
        if ($created && ! $pending) {
            $this->emitWallet($entry);
        }

        return $entry;
    }

    /**
     * Resta puntos confirmados (canje, ajuste o reembolso después de confirmar).
     * Con `requireBalance`, falla si no alcanzan.
     *
     * @param  array<string, mixed>  $redeemed  propiedades extra de points.redeemed (reward_id, reward_kind, coupon_code); vacío = sin evento
     */
    public function debitPoints(string $contactId, int $points, string $reason, string $idempotencyKey, ?string $refType = null, ?string $refId = null, bool $requireBalance = true, array $redeemed = [], ?string $parentId = null): LedgerEntry
    {
        if ($points <= 0) {
            throw new InvalidArgumentException(__('Los puntos deben ser mayores que cero.'));
        }
        $contactId = $this->live($contactId);
        $existing = LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $guard = $requireBalance ? function (array $before) use ($points): void {
            if ($before['points'] < $points) {
                throw new RuntimeException(__('No tienes puntos suficientes.'));
            }
        } : null;
        [$entry, $created] = $this->post($contactId, 'points', (string) -$points, 'confirmed', $reason, $idempotencyKey, $refType, $refId, $parentId, guard: $guard);
        if ($created && $redeemed !== []) {
            $this->emit('points.redeemed', $entry, array_filter([
                'ledger_id' => $entry->publicId(), 'points' => $points, 'balance_after' => (int) round((float) $entry->balance_after),
            ] + $redeemed, fn ($v) => $v !== null));
        }

        return $entry;
    }

    /** Devuelve puntos de un canje que no se completó (confirmados, sin evento de ganancia). */
    public function earnBack(string $contactId, int $points, string $idempotencyKey, string $parentId): void
    {
        $this->post($contactId, 'points', (string) $points, 'confirmed', 'redeem_undo', $idempotencyKey, 'ledger', $parentId, $parentId, $this->program()?->rules()->expiresAt(now()));
    }

    /**
     * Emite points.redeemed de un canje ya registrado (cuando el cupón ya tiene código).
     *
     * @param  array<string, mixed>  $properties
     */
    public function announceRedeemed(LedgerEntry $entry, array $properties): void
    {
        $this->emit('points.redeemed', $entry, [
            'ledger_id' => $entry->publicId(), 'points' => -$entry->points(), 'balance_after' => (int) round((float) $entry->balance_after),
        ] + $properties);
    }

    /**
     * Revierte parte de los puntos pendientes de una entrada (reembolso parcial
     * antes de confirmar). Devuelve los puntos revertidos.
     */
    public function reversePending(LedgerEntry $pending, int $points, string $idempotencyKey): int
    {
        $children = LedgerEntry::query()->where('parent_id', $pending->id)->get()->all();
        $remaining = (int) round((float) Balances::pendingRemaining($pending, $children));
        $points = min($points, $remaining);
        if ($points <= 0) {
            return 0;
        }
        [, $created] = $this->post($pending->contact_id, $pending->kind, (string) -$points, 'reversed', $pending->reason, $idempotencyKey, $pending->ref_type, $pending->ref_id, $pending->id);

        return $created ? $points : 0;
    }

    /** Vence lo que queda de los abonos con `expires_at` ya cumplido. Devuelve los puntos vencidos. */
    public function expire(string $contactId, ?CarbonImmutable $now = null): int
    {
        $total = 0;
        foreach ($this->balances->compute($contactId, $now)['expired_due'] as $due) {
            [$entry, $created] = $this->post($contactId, 'points', (string) -$due['remaining'], 'expired', 'expiry', 'expire:'.$due['entry']->id, 'ledger', $due['entry']->id, $due['entry']->id);
            if ($created) {
                $total += $due['remaining'];
                $this->emit('points.expired', $entry, ['ledger_id' => $entry->publicId(), 'points' => $due['remaining'], 'balance_after' => (int) round((float) $entry->balance_after)]);
            }
        }

        return $total;
    }

    public function pointsBalance(string $contactId): int
    {
        return $this->balances->compute($this->live($contactId))['points'];
    }

    public function walletBalance(string $contactId): string
    {
        return $this->balances->compute($this->live($contactId))['wallet'];
    }

    /** @return array{points: int, pending: int, wallet: string, expiring: int, expiring_at: string|null} */
    public function summary(string $contactId): array
    {
        $b = $this->balances->compute($this->live($contactId));
        unset($b['expired_due']);

        return $b;
    }

    /** Deja al día las columnas de saldo del contacto. */
    public function refresh(string $contactId): void
    {
        $b = $this->balances->compute($contactId);
        DB::table('contacts')->where('id', $contactId)->where('workspace_id', $this->current->id())->update([
            'points_balance' => $b['points'],
            'wallet_balance_ws' => $b['wallet'],
            'points_expiring' => $b['expiring'],
            'points_expiring_at' => $b['expiring_at'],
        ]);
    }

    /**
     * Pasa al contacto que quedó los saldos de los contactos fusionados en él
     * (MergeContacts no conoce el libro). Idempotente por contacto fusionado.
     */
    public function absorbMerged(string $winnerId): void
    {
        $losers = Contact::query()->where('merged_into_id', $winnerId)
            ->where(fn ($q) => $q->where('points_balance', '!=', 0)->orWhere('wallet_balance_ws', '!=', 0))->pluck('id');
        foreach ($losers as $loserId) {
            $loserId = (string) $loserId;
            $b = $this->balances->compute($loserId);
            if ($b['points'] !== 0) {
                $this->post($loserId, 'points', (string) -$b['points'], 'confirmed', 'merge', 'merge-out:points:'.$loserId, 'contact', $winnerId, live: false);
                $this->post($winnerId, 'points', (string) $b['points'], 'confirmed', 'merge', 'merge-in:points:'.$loserId, 'contact', $loserId, expiresAt: $this->program()?->rules()->expiresAt(now()));
            }
            if (! Decimal::equals($b['wallet'], '0')) {
                $currency = $this->current->get()->currency;
                $this->post($loserId, 'wallet', Decimal::sub('0', $b['wallet']), 'confirmed', 'merge', 'merge-out:wallet:'.$loserId, 'contact', $winnerId, currency: $currency, live: false);
                $this->post($winnerId, 'wallet', $b['wallet'], 'confirmed', 'merge', 'merge-in:wallet:'.$loserId, 'contact', $loserId, currency: $currency);
            }
        }
    }

    /**
     * Inserta una entrada (idempotente por clave) con el contacto bloqueado.
     *
     * @return array{0: LedgerEntry, 1: bool} la entrada y si se creó ahora
     */
    private function post(string $contactId, string $kind, string $delta, string $status, string $reason, string $key, ?string $refType = null, ?string $refId = null, ?string $parentId = null, ?CarbonInterface $expiresAt = null, ?string $currency = null, bool $live = true, ?callable $guard = null): array
    {
        $key = mb_substr($key, 0, 190);
        $existing = LedgerEntry::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return [$existing, false];
        }
        if ($live) {
            $contactId = $this->live($contactId);
        }
        try {
            $entry = DB::transaction(function () use ($contactId, $kind, $delta, $status, $reason, $key, $refType, $refId, $parentId, $expiresAt, $currency, $guard): LedgerEntry {
                DB::table('contacts')->where('id', $contactId)->lockForUpdate()->value('id');
                $again = LedgerEntry::query()->where('idempotency_key', $key)->first();
                if ($again !== null) {
                    return $again;
                }
                $before = $this->balances->compute($contactId);
                if ($guard !== null) {
                    $guard($before);
                }
                $base = $kind === 'points' ? (string) $before['points'] : $before['wallet'];
                $entry = LedgerEntry::query()->create([
                    'workspace_id' => $this->current->id(),
                    'contact_id' => $contactId,
                    'kind' => $kind,
                    'delta' => Decimal::of($delta),
                    'balance_after' => Decimal::add($base, self::effect($kind, $status, Decimal::of($delta), $parentId)),
                    'currency' => $kind === 'wallet' ? ($currency ?? $this->current->get()->currency) : null,
                    'status' => $status,
                    'reason' => mb_substr($reason, 0, 40),
                    'ref_type' => $refType !== null ? mb_substr($refType, 0, 20) : null,
                    'ref_id' => $refId !== null ? mb_substr($refId, 0, 60) : null,
                    'parent_id' => $parentId,
                    'idempotency_key' => $key,
                    'expires_at' => $expiresAt,
                ]);
                $this->refresh($contactId);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            return [LedgerEntry::query()->where('idempotency_key', $key)->firstOrFail(), false];
        }

        return [$entry, $entry->wasRecentlyCreated];
    }

    /**
     * Cuánto cambia el saldo disponible una entrada (ver Balances): los puntos
     * cuentan confirmados o vencidos; en la billetera, un débito pendiente ya
     * reduce lo disponible, su confirmación no lo vuelve a restar y su
     * liberación (`reversed`, positiva) lo devuelve.
     */
    private static function effect(string $kind, string $status, string $delta, ?string $parentId): string
    {
        if ($kind === 'points') {
            return in_array($status, ['confirmed', 'expired'], true) ? $delta : '0.00';
        }

        $negative = (float) $delta < 0;

        return match ($status) {
            // Confirmar un débito pendiente no resta otra vez; confirmar un abono pendiente sí suma.
            'confirmed' => $parentId !== null && $negative ? '0.00' : $delta,
            'pending' => $negative ? $delta : '0.00',
            // Liberar un débito pendiente devuelve lo reservado; revertir un abono pendiente no cambia nada.
            'reversed' => $negative ? '0.00' : $delta,
            default => '0.00',
        };
    }

    /** El contacto vigente (si se fusionó, el que quedó). */
    private function live(string $contactId): string
    {
        $id = $contactId;
        for ($i = 0; $i < 10; $i++) {
            $next = Contact::query()->whereKey($id)->value('merged_into_id');
            if (! is_string($next) || $next === '') {
                return $id;
            }
            $id = $next;
        }

        return $id;
    }

    private function emitWallet(LedgerEntry $entry): void
    {
        $credit = (float) $entry->delta > 0;
        $this->emit($credit ? 'wallet.credited' : 'wallet.debited', $entry, array_filter([
            'ledger_id' => $entry->publicId(),
            'amount' => Decimal::of(ltrim((string) $entry->delta, '-')),
            'currency' => $entry->currency ?? $this->current->get()->currency,
            'balance_after' => Decimal::of((string) $entry->balance_after),
            'reason' => $entry->reason,
            'ref_type' => $entry->ref_type,
            'ref_id' => $entry->ref_id,
        ], fn ($v) => $v !== null));
    }

    /** @param array<string, mixed> $properties */
    private function emit(string $event, LedgerEntry $entry, array $properties): void
    {
        try {
            $this->events->emit($this->current->get(), $event, array_filter($properties, fn ($v) => $v !== null), $entry->contact_id);
        } catch (Throwable $e) {
            // El libro es la fuente de verdad: un evento perdido no deshace el movimiento.
            report($e);
            Log::warning('igr.loyalty.event_failed', ['event' => $event, 'ledger' => $entry->id]);
        }
    }
}
