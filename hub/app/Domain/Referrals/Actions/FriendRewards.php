<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Commerce\Exceptions\CouponIssueException;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\Fingerprints;
use App\Domain\Referrals\Support\Programs;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * El regalo del amigo referido (modulos.md M14), que pide la captación (M12):
 *
 * 1. El código debe ser de un cliente y el programa estar activo.
 * 2. Nada para un autorreferido (mismo contacto, correo o teléfono) ni para quien ya
 *    es cliente o ya fue referido (por cualquiera).
 * 3. Registra el referido (`signed_up`) con sus señales de fraude; si hay señales,
 *    queda en revisión manual (la recompensa de quien refiere espera la decisión).
 * 4. Crea el cupón de primera compra solo para el amigo.
 *
 * Repetir la misma captura devuelve el mismo cupón: nunca se da dos veces.
 */
final class FriendRewards implements ReferralRewards
{
    public function __construct(
        private readonly Programs $programs,
        private readonly ReferralCodes $codes,
        private readonly Fingerprints $fingerprints,
        private readonly CouponIssuer $coupons,
        private readonly CurrentWorkspace $current,
    ) {}

    public function onFriendCaptured(string $contactId, string $code, ?string $anonymousId, bool $wasKnown, ?string $siteId = null, ?string $ip = null): ?CouponIssued
    {
        $settings = $this->programs->active();
        $friend = Contact::query()->find($contactId);
        $referrer = $this->codes->contactFor($code);
        if ($settings === null || $friend === null || $referrer === null) {
            return null;
        }
        if (Fingerprints::samePerson($referrer, $friend)) {
            Log::info('igr.referrals.self_referral', ['workspace' => $this->current->id(), 'referrer' => $referrer->id]);

            return null;
        }
        $existing = Referral::query()->where('friend_contact_id', $friend->id)->first();
        if ($existing !== null) {
            return $this->replay($existing, $referrer, $settings, $siteId);
        }
        if ($wasKnown && Order::query()->where('contact_id', $friend->id)->where('is_paid', true)->exists()) {
            return null; // el regalo es para la primera compra
        }

        $signals = $this->fingerprints->compare($this->current->id(), $referrer, $friend, ['anonymous_id' => $anonymousId, 'ip' => $ip], $settings->signalWindowDays());
        $referral = new Referral([
            'site_id' => $siteId,
            'referrer_contact_id' => $referrer->id,
            'code' => strtoupper($code),
            'friend_contact_id' => $friend->id,
            'status' => Referral::SIGNED_UP,
        ]);
        $referral->addSignals($signals, 'signup');
        if ($signals !== [] && $settings->reviewSuspicious()) {
            $referral->review_status = Referral::PENDING_REVIEW;
        }
        try {
            $referral->save();
        } catch (UniqueConstraintViolationException) {
            $existing = Referral::query()->where('friend_contact_id', $friend->id)->first();

            return $existing === null ? null : $this->replay($existing, $referrer, $settings, $siteId);
        }

        return $this->issue($referral, $settings, $siteId);
    }

    /** La misma persona vuelve con el mismo código: su cupón (o crearlo si falló antes). */
    private function replay(Referral $referral, Contact $referrer, ProgramSettings $settings, ?string $siteId): ?CouponIssued
    {
        if ($referral->referrer_contact_id !== $referrer->id || $referral->status !== Referral::SIGNED_UP) {
            return null;
        }
        if ($referral->friend_coupon_id !== null) {
            $coupon = CouponIssued::query()->find($referral->friend_coupon_id);

            return $coupon !== null && $coupon->status === 'active' ? $coupon : null;
        }

        return $this->issue($referral, $settings, $siteId ?? $referral->site_id);
    }

    private function issue(Referral $referral, ProgramSettings $settings, ?string $siteId): ?CouponIssued
    {
        try {
            $coupon = $this->coupons->issue($settings->friendCoupon((string) $referral->friend_contact_id, $referral->id, $siteId));
        } catch (CouponIssueException) {
            Log::warning('igr.referrals.friend_coupon_failed', ['referral' => $referral->id]);

            return null;
        }
        $referral->forceFill(['friend_coupon_id' => $coupon->id])->save();

        return $coupon;
    }
}
