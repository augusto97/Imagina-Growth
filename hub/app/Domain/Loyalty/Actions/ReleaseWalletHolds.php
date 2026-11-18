<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Loyalty\Ledger\Balances;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Sites\Actions\SendSiteCommand;
use App\Domain\Sites\Models\Site;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cupones de saldo que vencieron sin usarse (24 h, api-connector.md §5.5):
 * se desactiva el cupón en la tienda (`coupon.disable`) y se libera el saldo
 * reservado. Si un pedido aún sin pagar lo usa (PSE, contraentrega), se espera
 * a que se pague o se cancele. Si la tienda no responde, se reintenta en la
 * próxima pasada (salvo que el cupón ya haya vencido también en la tienda).
 */
final class ReleaseWalletHolds
{
    public function __construct(private readonly DatabaseLoyaltyLedger $ledger, private readonly SendSiteCommand $command) {}

    /** @return int saldos liberados */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $released = 0;
        $holds = LedgerEntry::query()->where('kind', 'wallet')->where('status', 'pending')->where('ref_type', 'coupon')
            ->where('created_at', '<=', $now->subHours(RedeemWallet::HOLD_HOURS))->limit(500)->get();
        foreach ($holds as $hold) {
            if (Decimal::equals(Balances::pendingRemaining($hold, LedgerEntry::query()->where('parent_id', $hold->id)->get()->all()), '0')) {
                continue;
            }
            $coupon = CouponIssued::query()->find((string) $hold->ref_id);
            if ($coupon !== null && $this->usedByOpenOrder($coupon)) {
                continue;
            }
            if ($coupon !== null && $coupon->status === 'redeemed') {
                // Un pedido pagado lo usó y el evento no confirmó el débito: se confirma ahora.
                $this->ledger->settle('coupon', $coupon->id, true);

                continue;
            }
            if ($coupon !== null && in_array($coupon->status, ['active', 'pending'], true) && ! $this->disable($coupon, $now)) {
                continue;
            }
            $this->ledger->settle('coupon', (string) $hold->ref_id, false);
            $coupon?->forceFill(['status' => 'expired'])->save();
            $released++;
        }

        return $released;
    }

    private function usedByOpenOrder(CouponIssued $coupon): bool
    {
        return Order::query()->where('site_id', $coupon->site_id)->where('is_paid', false)
            ->whereNotIn('status', ['cancelled', 'failed', 'refunded', 'trash', 'checkout-draft'])
            ->where(fn ($q) => $q->whereJsonContains('coupon_codes', $coupon->code)->orWhereJsonContains('coupon_codes', strtolower($coupon->code)))
            ->exists();
    }

    private function disable(CouponIssued $coupon, CarbonImmutable $now): bool
    {
        $site = Site::query()->find($coupon->site_id);
        if ($site === null || ! $site->isActive()) {
            return true;
        }
        try {
            $this->command->handle($site, 'coupon.disable', ['code' => $coupon->code], timeout: 10);

            return true;
        } catch (RuntimeException $e) {
            Log::warning('igr.loyalty.wallet_disable_failed', ['coupon' => $coupon->id, 'site' => $site->id]);

            // Si en la tienda ya venció (fin del día de expires_at), nadie lo puede usar: se libera igual.
            return $coupon->expires_at !== null && $coupon->expires_at->lt($now);
        }
    }
}
