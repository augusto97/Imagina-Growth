<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Actions\SiteCouponIssuer;
use App\Domain\Commerce\Contracts\CouponIssuer;
use Illuminate\Support\ServiceProvider;

/** Comercio: carritos, cupones (hub/CLAUDE.md §5). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CouponIssuer::class, SiteCouponIssuer::class);
    }
}
