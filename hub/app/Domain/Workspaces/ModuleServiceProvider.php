<?php

declare(strict_types=1);

namespace App\Domain\Workspaces;

use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Support\DefaultBranding;
use Illuminate\Support\ServiceProvider;

/** Contratos por defecto de cuentas y espacios de trabajo (la agencia reemplaza la marca, M22). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(Branding::class, DefaultBranding::class);
    }
}
