<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Projectors\FxConverter;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Loyalty\Ledger\Balances;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Sites\Models\Site;
use App\Support\Money\Decimal;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * «Usar saldo» en el checkout (api-connector.md §5.5): cupón de un solo uso
 * por `min(saldo, total del carrito)` y débito **pendiente** hasta que un
 * `order.paid` lo use; se libera si el pedido se cancela o el cupón vence sin
 * usarse (24 h). Un segundo clic con el mismo carrito devuelve el mismo cupón.
 */
final class RedeemWallet
{
    /** Horas que el cupón de saldo reserva el saldo. */
    public const HOLD_HOURS = 24;

    public function __construct(
        private readonly DatabaseLoyaltyLedger $ledger,
        private readonly CouponIssuer $coupons,
        private readonly CurrentWorkspace $current,
        private readonly FxConverter $fx,
    ) {}

    /**
     * @return array{coupon: CouponIssued, amount: string, expires_at: CarbonImmutable, balance: string}|null null = sin saldo
     *
     * @throws RuntimeException si el cupón no se pudo crear o la moneda no se puede convertir
     */
    public function handle(Site $site, string $customerRef, string $cartTotal, string $currency, ?string $cartToken): ?array
    {
        $contactId = ContactIdentity::query()->where('type', 'customer_ref')->where('value', $site->id.':'.$customerRef)->value('contact_id');
        if (! is_string($contactId)) {
            return null;
        }
        $lock = Cache::lock('igr:loyalty:wallet:'.$contactId, 20);
        if (! $lock->get()) {
            throw new RuntimeException('busy');
        }
        try {
            if ($cartToken !== null && ($same = $this->sameCart($contactId, $cartToken)) !== null) {
                return $same;
            }
            $available = $this->ledger->walletBalance($contactId);
            if ((float) $available <= 0) {
                return null;
            }
            $workspace = $this->current->get();
            $currency = strtoupper($currency);
            // El saldo vive en la moneda del espacio de trabajo; el cupón va en la de la tienda.
            $rate = $currency === $workspace->currency ? '1' : $this->fx->rate($workspace->currency, $currency, now());
            if ($rate === null) {
                throw new RuntimeException('currency');
            }
            $availableInCart = Decimal::mul($available, $rate);
            $couponAmount = (float) $availableInCart <= (float) $cartTotal ? $availableInCart : Decimal::of($cartTotal);
            if ((float) $couponAmount <= 0) {
                return null;
            }
            $debitWs = (float) $couponAmount === (float) $availableInCart ? $available : Decimal::div($couponAmount, $rate);

            $coupon = $this->coupons->issue(new CouponSpec(
                kind: 'fixed_cart',
                amount: $couponAmount,
                sourceType: 'wallet',
                sourceId: $cartToken !== null ? mb_substr($cartToken, 0, 40) : null,
                contactId: $contactId,
                expiresInDays: 1,
                prefix: 'SALDO',
                individualUse: false,
                siteId: $site->id,
                description: __('Saldo a favor'),
            ));
            $this->ledger->debitWallet($contactId, $debitWs, 'order', 'wallet-redeem:'.$coupon->id, 'coupon', $coupon->id, pending: true, allowPartial: true);

            return ['coupon' => $coupon, 'amount' => (string) $coupon->amount, 'expires_at' => CarbonImmutable::instance($coupon->created_at ?? now())->addHours(self::HOLD_HOURS), 'balance' => $available];
        } finally {
            $lock->release();
        }
    }

    /** @return array{coupon: CouponIssued, amount: string, expires_at: CarbonImmutable, balance: string}|null */
    private function sameCart(string $contactId, string $cartToken): ?array
    {
        $coupon = CouponIssued::query()->where('source_type', 'wallet')->where('contact_id', $contactId)->where('source_id', mb_substr($cartToken, 0, 40))
            ->where('status', 'active')->where('created_at', '>', now()->subHours(self::HOLD_HOURS))->latest('created_at')->first();
        if ($coupon === null) {
            return null;
        }
        $debit = LedgerEntry::query()->where('idempotency_key', 'wallet-redeem:'.$coupon->id)->first();
        if ($debit === null || ! Decimal::equals(Balances::pendingRemaining($debit, LedgerEntry::query()->where('parent_id', $debit->id)->get()->all()), (string) $debit->delta)) {
            return null;
        }

        return [
            'coupon' => $coupon,
            'amount' => (string) $coupon->amount,
            'expires_at' => CarbonImmutable::instance($coupon->created_at ?? now())->addHours(self::HOLD_HOURS),
            'balance' => Decimal::add($this->ledger->walletBalance($contactId), ltrim((string) $debit->delta, '-')),
        ];
    }
}
