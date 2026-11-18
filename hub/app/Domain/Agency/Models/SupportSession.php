<?php

declare(strict_types=1);

namespace App\Domain\Agency\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entrada de alguien de la agencia (o de soporte) a un espacio de trabajo del que
 * no es miembro directo (M22). Además queda en `audit_logs`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $reason
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $ip_hash
 */
class SupportSession extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'sup';

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'user_id', 'reason', 'started_at', 'ended_at', 'ip_hash'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
