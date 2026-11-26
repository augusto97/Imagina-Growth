<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Support\NullLeadIntake;
use App\Domain\Services\Verticals\ApplyDefaultsOnVerticalChange;
use App\Domain\Workspaces\Events\WorkspaceVerticalChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * M18 Servicios y leads: contrato por defecto (el módulo de leads lo reemplaza) y los valores por defecto de la
 * vertical al elegirla (ApplyVerticalDefaults).
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(LeadIntake::class, NullLeadIntake::class);
    }

    public function boot(): void
    {
        Event::listen(WorkspaceVerticalChanged::class, ApplyDefaultsOnVerticalChange::class);
    }
}
