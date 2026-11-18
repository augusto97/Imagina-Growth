<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Data\CouponSpec;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyReward;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Canje de puntos por una recompensa (modulos.md M13): resta los puntos y
 * emite un cupón único de WooCommerce por comando firmado (CouponIssuer,
 * `source_type: loyalty`) o, si la recompensa es saldo, lo acredita. Si el
 * cupón no se puede crear, los puntos vuelven. Un canje a la vez por contacto.
 */
final class RedeemReward
{
    public function __construct(private readonly DatabaseLoyaltyLedger $ledger, private readonly CouponIssuer $coupons) {}

    /**
     * @return array{coupon: CouponIssued|null, wallet: string|null, balance: int}
     *
     * @throws RuntimeException con un mensaje para la persona
     */
    public function handle(string $contactId, LoyaltyReward $reward, ?string $siteId = null): array
    {
        $program = $this->ledger->program();
        if ($program === null || ! $program->isActive() || $reward->status !== 'active' || $reward->program_id !== $program->id) {
            throw new RuntimeException(__('Esta recompensa no está disponible.'));
        }
        $rules = $program->rules();
        $lock = Cache::lock('igr:loyalty:redeem:'.$contactId, 30);
        if (! $lock->get()) {
            throw new RuntimeException(__('Ya estamos procesando otro canje. Intenta de nuevo en un momento.'));
        }
        try {
            $balance = $this->ledger->pointsBalance($contactId);
            if ($balance < max($reward->points_cost, $rules->minRedeemPoints)) {
                throw new RuntimeException(__('No tienes puntos suficientes.'));
            }
            $debit = $this->ledger->debitPoints($contactId, $reward->points_cost, 'redeem', 'redeem:'.Str::ulid(), 'reward', $reward->id);

            if ($reward->kind === 'wallet') {
                $this->ledger->creditWallet($contactId, (string) $reward->value, 'redeem', 'redeem-wallet:'.$debit->id, 'reward', $reward->id);
                $this->ledger->announceRedeemed($debit, ['reward_id' => $reward->publicId(), 'reward_kind' => $reward->kind]);

                return ['coupon' => null, 'wallet' => $this->ledger->walletBalance($contactId), 'balance' => $this->ledger->pointsBalance($contactId)];
            }

            try {
                $coupon = $this->coupons->issue($this->spec($reward, $contactId, $rules->couponExpiryDays, $siteId));
            } catch (CouponIssueException $e) {
                // Los puntos vuelven: el canje no se completó.
                $this->ledger->earnBack($contactId, $reward->points_cost, 'redeem-undo:'.$debit->id, $debit->id);

                throw new RuntimeException(__('No pudimos crear tu cupón ahora. Tus puntos no se descontaron; intenta de nuevo.'), 0, $e);
            }
            $this->ledger->announceRedeemed($debit, ['reward_id' => $reward->publicId(), 'reward_kind' => $reward->kind, 'coupon_code' => $coupon->code]);

            return ['coupon' => $coupon, 'wallet' => null, 'balance' => $this->ledger->pointsBalance($contactId)];
        } finally {
            $lock->release();
        }
    }

    private function spec(LoyaltyReward $reward, string $contactId, int $defaultDays, ?string $siteId): CouponSpec
    {
        $config = (array) ($reward->config ?? []);
        $product = isset($config['product_id']) && $config['product_id'] !== '' ? [(string) $config['product_id']] : [];
        [$kind, $amount] = match ($reward->kind) {
            'coupon_percent' => ['percent', (string) $reward->value],
            'free_shipping' => ['free_shipping', '0.00'],
            // Producto gratis: descuento fijo hasta el valor del producto, solo si está en el carrito.
            default => ['fixed_cart', (string) $reward->value],
        };
        $minimum = isset($config['min_order']) && is_numeric($config['min_order']) && (float) $config['min_order'] > 0 ? number_format((float) $config['min_order'], 2, '.', '') : null;

        return new CouponSpec(
            kind: $kind,
            amount: number_format((float) $amount, 2, '.', ''),
            sourceType: 'loyalty',
            sourceId: $reward->id,
            contactId: $contactId,
            expiresInDays: max(1, min(365, (int) ($config['expires_in_days'] ?? $defaultDays))),
            prefix: 'PUNTOS',
            minimumAmount: $minimum,
            productIds: $reward->kind === 'free_product' ? $product : [],
            siteId: $siteId,
            description: __('Canje de puntos: :name', ['name' => mb_substr($reward->name, 0, 120)]),
        );
    }
}
