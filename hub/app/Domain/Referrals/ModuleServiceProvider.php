<?php

declare(strict_types=1);

namespace App\Domain\Referrals;

use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Referrals\Support\NullReferralRewards;
use Illuminate\Support\ServiceProvider;

/** Referidos, afiliados e influencers (M14). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReferralRewards::class, NullReferralRewards::class);
    }
}
