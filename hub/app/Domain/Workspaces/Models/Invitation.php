<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Models;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class Invitation extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'inv';

    protected $table = 'workspace_invitations';

    protected $fillable = ['workspace_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['role' => WorkspaceRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
