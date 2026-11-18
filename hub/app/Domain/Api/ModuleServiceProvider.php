<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * API pública v1 y servidor MCP (modulos.md M19): tokens por espacio de trabajo con
 * alcances, documentación OpenAPI con Scramble (solo para quien administra la API) y las
 * pantallas de «API e integraciones».
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // La documentación (/api/docs) la ve quien puede administrar la API en algún espacio de trabajo.
        Gate::define('viewApiDocs', fn (?User $user): bool => $user !== null && DB::table('workspace_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->whereIn('role', array_map(fn (WorkspaceRole $r) => $r->value, array_filter(WorkspaceRole::cases(), fn (WorkspaceRole $r) => $r->can('manageApi'))))
            ->exists());

        if (class_exists(Scramble::class)) {
            // En /api/docs: /docs/* lo atiende la SPA (routes/web.php).
            Scramble::configure()
                ->expose(ui: 'api/docs', document: 'api/docs.json')
                ->routes(fn (Route $route): bool => str_starts_with($route->uri, 'api/v1/') && ! $route->isFallback)
                ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->setDescription((string) __('API pública de Imagina Growth. Autenticación con un token del espacio de trabajo (`Authorization: Bearer igr_…`) creado en «API e integraciones». Cada token tiene alcances; los errores vienen como `{"error": {"code", "message", "fields"}}`. Guía completa: docs/api-publica.md.'));
                $openApi->secure(SecurityScheme::http('bearer'));
            });
        }
    }
}
