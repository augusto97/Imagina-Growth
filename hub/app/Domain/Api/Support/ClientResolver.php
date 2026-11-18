<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

use App\Domain\Api\Models\ApiClient;
use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Del token Bearer al cliente y su espacio de trabajo, para la API pública y el
 * servidor MCP. Devuelve el error (código, mensaje, estado) si no se puede usar.
 */
final class ClientResolver
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  'api'|'mcp'  $module
     * @return array{client: ApiClient, workspace: Workspace}|array{error: array{0: int, 1: string, 2: string}}
     */
    public function resolve(?string $bearer, string $module): array
    {
        if ($bearer === null || $bearer === '' || ! str_starts_with($bearer, 'igr_')) {
            return ['error' => [401, 'unauthenticated', ApiError::defaultMessage(401)]];
        }
        $token = PersonalAccessToken::findToken(substr($bearer, 4));
        if ($token === null || $token->tokenable_type !== ApiClient::class || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return ['error' => [401, 'unauthenticated', ApiError::defaultMessage(401)]];
        }
        /** @var ApiClient|null $client */
        $client = ApiClient::withoutWorkspaceScope()->find($token->tokenable_id);
        $workspace = $client !== null ? Workspace::query()->find($client->workspace_id) : null;
        if ($client === null || $workspace === null || $client->isRevoked()) {
            return ['error' => [401, 'token_revoked', __('Este token fue revocado.')]];
        }
        if ($module === 'mcp' && ! $client->can('mcp')) {
            return ['error' => [403, 'forbidden', __('Este token no tiene el alcance «mcp».')]];
        }
        if (! $this->entitlements->allows($workspace, $module)) {
            return ['error' => [402, 'plan_required', $module === 'mcp' ? __('Tu plan no incluye el servidor MCP.') : ApiError::defaultMessage(402)]];
        }
        // Último uso: como mucho una escritura por minuto y token.
        if (Cache::add('api:last_used:'.$client->id, 1, 60)) {
            ApiClient::withoutWorkspaceScope()->whereKey($client->id)->update(['last_used_at' => now()]);
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return ['client' => $client, 'workspace' => $workspace];
    }

    public function perMinute(Workspace $workspace): int
    {
        return max(1, $this->entitlements->limit($workspace, 'api_requests_per_minute') ?? (int) config('igr.api.per_minute', 120));
    }
}
