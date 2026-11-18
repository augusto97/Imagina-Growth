<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Attribution;

use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Referrals\Actions\FlagReferralSignals;
use App\Domain\Referrals\Actions\SyncReferralOrder;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\Commission;
use App\Domain\Referrals\Support\Fingerprints;
use App\Domain\Referrals\Support\OrderState;
use App\Domain\Referrals\Support\Programs;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Paso 3 de AttributeOrder (hub/CLAUDE.md §9): el pedido pagado se atribuye a un
 * afiliado o a un referido, nunca a los dos. Orden de preferencia:
 *
 * 1. Cupón de un afiliado.
 * 2. Cupón de amigo de un referido.
 * 3. `referral_code` del último (o primer) contacto (`?ref=`): enlace de afiliado o
 *    código de un cliente.
 * 4. Un referido ya registrado (`signed_up`) de esa persona.
 *
 * Un referido solo cuenta con la **primera** compra pagada del amigo. Si el amigo
 * llegó solo con el enlace (sin dejar sus datos antes), el referido se crea aquí.
 * Debe correr dentro del contexto del espacio de trabajo.
 */
final class ReferralAttributor
{
    public function __construct(
        private readonly ReferralCodes $codes,
        private readonly Programs $programs,
        private readonly Fingerprints $fingerprints,
        private readonly SyncReferralOrder $sync,
        private readonly FlagReferralSignals $signals,
    ) {}

    /**
     * @param  array{first_touch?: array<string, mixed>|null, last_touch?: array<string, mixed>|null, anonymous_id?: string|null}  $hints
     */
    public function attribute(Workspace $workspace, Order $order, array $hints = []): void
    {
        if ($order->getAttribute('attributed_referral_id') !== null || $order->getAttribute('attributed_affiliate_id') !== null) {
            $this->sync->handle($order);

            return;
        }
        $coupons = array_values(array_unique(array_map(fn ($c) => mb_strtolower(trim((string) $c)), $order->coupon_codes ?? [])));
        $touchCode = self::touchCode($hints);

        $affiliate = $this->affiliateByCoupon($coupons);
        if ($affiliate !== null) {
            $this->toAffiliate($order, $affiliate, 'coupon');

            return;
        }
        $referral = $this->referralByCoupon($coupons, $order);
        if ($referral === null && $touchCode !== null) {
            $linked = $this->codes->affiliateFor($touchCode);
            if ($linked !== null) {
                $this->toAffiliate($order, $linked, 'link');

                return;
            }
            $referral = $this->referralByCode($workspace, $touchCode, $order, $hints['anonymous_id'] ?? null);
        }
        if ($referral === null && $order->contact_id !== null) {
            $referral = Referral::query()->where('friend_contact_id', $order->contact_id)->where('status', Referral::SIGNED_UP)->first();
        }
        if ($referral !== null) {
            $this->toReferral($order, $referral);
        }
    }

    /** @param array<string, mixed> $hints */
    public static function touchCode(array $hints): ?string
    {
        foreach (['last_touch', 'first_touch'] as $key) {
            $code = is_array($hints[$key] ?? null) ? ($hints[$key]['referral_code'] ?? null) : null;
            if (is_string($code) && preg_match(ReferralCodes::PATTERN, $code) === 1) {
                return strtoupper($code);
            }
        }

        return null;
    }

    /** @param list<string> $coupons */
    private function affiliateByCoupon(array $coupons): ?Affiliate
    {
        if ($coupons === []) {
            return null;
        }
        foreach (Affiliate::query()->where('status', 'active')->whereNotNull('coupon_codes')->get() as $affiliate) {
            if (array_intersect($affiliate->normalizedCoupons(), $coupons) !== []) {
                return $affiliate;
            }
        }

        return null;
    }

    /** @param list<string> $coupons */
    private function referralByCoupon(array $coupons, Order $order): ?Referral
    {
        if ($coupons === []) {
            return null;
        }
        $couponIds = CouponIssued::query()->where('site_id', $order->site_id)->where('source_type', 'referral')
            ->whereIn('code', array_map('strtoupper', $coupons))->pluck('id')->all();

        return $couponIds === [] ? null : Referral::query()->whereIn('friend_coupon_id', $couponIds)->where('status', Referral::SIGNED_UP)->first();
    }

    /** El amigo llegó por el enlace de un cliente: su referido (o uno nuevo si nunca dejó sus datos). */
    private function referralByCode(Workspace $workspace, string $code, Order $order, mixed $anonymousId): ?Referral
    {
        $settings = $this->programs->active();
        $referrer = $this->codes->contactFor($code);
        $friend = $order->contact_id !== null ? Contact::query()->find($order->contact_id) : null;
        if ($settings === null || $referrer === null || $friend === null || Fingerprints::samePerson($referrer, $friend)) {
            return null;
        }
        $existing = Referral::query()->where('friend_contact_id', $friend->id)->first();
        if ($existing !== null) {
            return $existing->status === Referral::SIGNED_UP ? $existing : null;
        }
        $signals = $this->fingerprints->compare($workspace->id, $referrer, $friend, ['anonymous_id' => is_string($anonymousId) ? $anonymousId : null], $settings->signalWindowDays());
        $referral = new Referral([
            'site_id' => $order->site_id,
            'referrer_contact_id' => $referrer->id,
            'code' => $code,
            'friend_contact_id' => $friend->id,
            'status' => Referral::SIGNED_UP,
        ]);
        $referral->addSignals($signals, 'order');
        if ($signals !== [] && $settings->reviewSuspicious()) {
            $referral->review_status = Referral::PENDING_REVIEW;
        }
        try {
            $referral->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $referral;
    }

    private function toReferral(Order $order, Referral $referral): void
    {
        if ($order->contact_id !== $referral->friend_contact_id || ! $order->is_paid || $this->hasEarlierPaidOrder($order)) {
            return; // solo la primera compra pagada del amigo
        }
        // Ahora el amigo ya dejó huellas en su pedido (IP y destinatario).
        $this->signals->forFriend($referral);
        $referral->forceFill([
            'status' => Referral::ORDERED,
            'order_id' => $order->id,
            'ordered_at' => $order->paid_at ?? $order->created_at ?? CarbonImmutable::now(),
            'order_amount_ws' => OrderState::amountWs($order),
        ])->save();
        $order->forceFill(['attributed_referral_id' => $referral->id])->save();
        $this->sync->handle($order);
    }

    private function toAffiliate(Order $order, Affiliate $affiliate, string $via): void
    {
        $calc = Commission::for($affiliate, $order);
        try {
            AffiliateConversion::query()->firstOrCreate(
                ['affiliate_id' => $affiliate->id, 'order_id' => $order->id],
                ['via' => $via, 'amount_ws' => $calc['amount'], 'commission_ws' => $calc['commission'], 'status' => 'pending'],
            );
        } catch (UniqueConstraintViolationException) {
            // Ya estaba: la misma atribución corrió dos veces.
        }
        $order->forceFill(['attributed_affiliate_id' => $affiliate->id])->save();
        $this->sync->handle($order);
    }

    private function hasEarlierPaidOrder(Order $order): bool
    {
        return Order::query()->where('contact_id', $order->contact_id)->where('is_paid', true)->whereKeyNot($order->id)
            ->where('created_at', '<', $order->created_at)->exists();
    }
}
