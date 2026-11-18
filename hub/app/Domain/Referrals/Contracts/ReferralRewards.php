<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Contracts;

use App\Domain\Commerce\Models\CouponIssued;

/**
 * Regalo del amigo referido (M14). Lo llama la captación (M12) cuando alguien deja
 * sus datos con un `referral_code` (widget `referral_welcome` o cualquier widget):
 * registra el referido (`signed_up`) con sus señales de fraude y devuelve el cupón
 * de primera compra, o null si el código no existe, el programa está apagado, es
 * un autorreferido o esa persona ya fue referida.
 */
interface ReferralRewards
{
    public function onFriendCaptured(string $contactId, string $code, ?string $anonymousId, bool $wasKnown): ?CouponIssued;
}
