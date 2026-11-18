<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Support;

use App\Domain\Api\Models\ApiClient;
use App\Domain\Api\Support\ApiContext;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Laravel\Mcp\Response;

/**
 * El token y el espacio de trabajo de la sesión MCP. Los pone AuthenticateApiClient (con el
 * módulo `mcp`) antes de que el servidor atienda la llamada.
 */
final class McpContext
{
    public static function client(): ?ApiClient
    {
        $client = request()->attributes->get(ApiContext::ATTRIBUTE);

        return $client instanceof ApiClient ? $client : null;
    }

    public static function can(string $scope): bool
    {
        return self::client()?->can($scope) ?? false;
    }

    public static function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->get();
    }

    /** Error de alcance o null si el token lo tiene. */
    public static function deny(string $scope): ?Response
    {
        return self::can($scope) ? null : Response::error(__('Este token no tiene el alcance «:scope». Créalo de nuevo en API e integraciones con ese alcance.', ['scope' => $scope]));
    }

    public static function panelUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/w/'.self::workspace()->publicId().'/'.ltrim($path, '/');
    }
}
