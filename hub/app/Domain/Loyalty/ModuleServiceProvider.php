<?php

declare(strict_types=1);

namespace App\Domain\Loyalty;

use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Loyalty\Support\NullLoyaltyLedger;
use Illuminate\Support\ServiceProvider;

/** Fidelización (M13): puntos, niveles VIP y saldo a favor. */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoyaltyLedger::class, NullLoyaltyLedger::class);
    }
}
