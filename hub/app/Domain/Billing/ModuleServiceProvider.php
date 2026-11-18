<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Support\UnlimitedEntitlements;
use Illuminate\Support\ServiceProvider;

/** M21 Planes y facturación: contrato por defecto (todo permitido hasta que haya planes). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(Entitlements::class, UnlimitedEntitlements::class);
    }
}
