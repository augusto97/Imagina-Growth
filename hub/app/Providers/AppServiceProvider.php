<?php

namespace App\Providers;

use App\Analytics\Ch;
use App\Analytics\Console\ClickHouseMigrateCommand;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Ingest\Console\IngestWorkCommand;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->singleton(Ch::class, fn () => Ch::fromConfig());
        $this->app->singleton(SchemaRegistry::class, fn () => new SchemaRegistry((string) config('igr.ingest.schemas_path')));

        // Cada módulo registra listeners, comandos y tareas programadas en su propio
        // proveedor (app/Domain/<Módulo>/ModuleServiceProvider.php), sin tocar este archivo.
        foreach (glob(app_path('Domain/*/ModuleServiceProvider.php')) ?: [] as $file) {
            $class = 'App\\Domain\\'.basename(dirname($file)).'\\ModuleServiceProvider';
            if (class_exists($class)) {
                $this->app->register($class);
            }
        }
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Permisos por rol del espacio de trabajo activo: Gate::authorize('ws.manageSites').
        foreach (WorkspaceRole::abilities() as $ability) {
            Gate::define('ws.'.$ability, fn (User $user): bool => app(CurrentWorkspace::class)->role()?->can($ability) ?? false);
        }

        // El enlace de restablecer va siempre al dominio del panel, nunca al `Host` de la petición: con proxies de
        // confianza `*`, alguien podría pedir el correo de otra persona con su dominio y robarle el token (S4).
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim((string) config('app.url'), '/')
            .'/restablecer/'.$token.'?email='.rawurlencode($user->getEmailForPasswordReset()));

        RateLimiter::for('connect', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ClickHouseMigrateCommand::class, IngestWorkCommand::class]);
        }
    }
}
