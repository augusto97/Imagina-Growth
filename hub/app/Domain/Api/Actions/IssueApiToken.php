<?php

declare(strict_types=1);

namespace App\Domain\Api\Actions;

use App\Domain\Api\Models\ApiClient;
use App\Domain\Api\Support\Scopes;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Crea un cliente de la API con su token. El token (`igr_…`) se devuelve una sola vez;
 * el hub guarda solo su hash (Sanctum) y los primeros caracteres para reconocerlo.
 */
final class IssueApiToken
{
    public const MAX_ACTIVE = 50;

    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  list<string>  $scopes
     * @return array{0: ApiClient, 1: string}
     */
    public function handle(string $workspaceId, string $name, string $kind, array $scopes, ?string $userId, ?string $preset = null): array
    {
        $scopes = Scopes::clean($kind === 'mcp' ? [...$scopes, 'mcp'] : $scopes);
        $client = ApiClient::query()->create([
            'workspace_id' => $workspaceId, 'name' => mb_substr($name, 0, 120), 'kind' => $kind, 'preset' => $preset,
            'scopes' => $scopes, 'created_by' => $userId,
        ]);
        $plain = 'igr_'.$client->createToken($kind.':'.$client->id, $scopes)->plainTextToken;
        $client->forceFill(['token_prefix' => mb_substr($plain, 0, 12)])->save();
        $this->audit->handle('api_token.created', $client, ['name' => $client->name, 'kind' => $kind, 'scopes' => $scopes, 'preset' => $preset], $workspaceId);

        return [$client, $plain];
    }

    public function revoke(ApiClient $client): void
    {
        if ($client->revoked_at !== null) {
            return;
        }
        $client->forceFill(['revoked_at' => now()])->save();
        $client->tokens()->delete();
        $this->audit->handle('api_token.revoked', $client, ['name' => $client->name], $client->workspace_id);
    }
}
