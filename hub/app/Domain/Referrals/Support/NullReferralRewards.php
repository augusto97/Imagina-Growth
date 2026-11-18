<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Referrals\Contracts\ReferralRewards;

/** Hasta que M14 registre el programa real: sin regalo. */
final class NullReferralRewards implements ReferralRewards
{
    public function onFriendCaptured(string $contactId, string $code, ?string $anonymousId, bool $wasKnown): ?CouponIssued
    {
        return null;
    }
}
