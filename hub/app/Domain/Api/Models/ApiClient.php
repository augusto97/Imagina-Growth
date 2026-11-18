<?php

declare(strict_types=1);

namespace App\Domain\Api\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Un cliente de la API pública o del servidor MCP de un espacio de trabajo (M19):
 * «Zapier», «Imagina CRM», «Claude (MCP)». Es el dueño del token de Sanctum, así que el
 * token es del espacio de trabajo y no de una persona: sobrevive aunque quien lo creó
 * salga del equipo. Los alcances viven aquí y en las habilidades del token.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $kind token | mcp
 * @property string|null $preset crm | base | reports | proposals | search | turbo
 * @property list<string> $scopes
 * @property string|null $token_prefix
 * @property string|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
class ApiClient extends Model
{
    use BelongsToWorkspace, HasApiTokens, HasPrefixedUlid;

    public const ID_PREFIX = 'apc';

    protected $fillable = ['workspace_id', 'name', 'kind', 'preset', 'scopes', 'token_prefix', 'created_by', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function can(string $scope): bool
    {
        return $this->revoked_at === null && in_array($scope, (array) $this->scopes, true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
