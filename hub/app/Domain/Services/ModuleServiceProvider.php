<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Support\NullLeadIntake;
use Illuminate\Support\ServiceProvider;

/** M18 Servicios y leads: contrato por defecto (el módulo de leads lo reemplaza). */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(LeadIntake::class, NullLeadIntake::class);
    }
}
