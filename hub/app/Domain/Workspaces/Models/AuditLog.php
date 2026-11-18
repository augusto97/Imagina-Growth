<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría (modelo-de-datos.md §1). Solo inserción.
 *
 * @property int $id
 * @property string|null $workspace_id
 * @property string $action
 * @property array<string, mixed>|null $changes
 */
class AuditLog extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'account_id', 'user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_hash'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }
}
