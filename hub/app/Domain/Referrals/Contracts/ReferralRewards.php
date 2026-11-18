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
 *
 * `siteId` es el sitio donde se capturó (ahí se crea el cupón; null = el primer
 * sitio activo) e `ip` la del visitante, solo para comparar su hash con las
 * huellas de quien refiere (nunca se guarda en claro). Ambos son opcionales.
 */
interface ReferralRewards
{
    public function onFriendCaptured(string $contactId, string $code, ?string $anonymousId, bool $wasKnown, ?string $siteId = null, ?string $ip = null): ?CouponIssued;
}
