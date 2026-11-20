<?php

namespace App\Providers;

use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // Solo el equipo de la plataforma (users.is_platform_admin) y, como en /api/platform, con la verificación
            // en dos pasos activa (S15): Horizon muestra los datos de cada trabajo y permite reintentarlos.
            if (! $user instanceof User || ! (bool) ($user->getAttributes()['is_platform_admin'] ?? false)) {
                return false;
            }

            return ! config('igr.require_two_factor') || $user->hasTwoFactorEnabled();
        });
    }
}
