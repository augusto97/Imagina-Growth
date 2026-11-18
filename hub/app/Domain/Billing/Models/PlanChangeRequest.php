<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Support\Ids\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $account_id
 * @property string|null $workspace_id
 * @property string|null $user_id
 * @property string|null $from_plan
 * @property string $to_plan
 * @property string|null $note
 * @property string $status pending | applied | rejected
 * @property string|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 */
class PlanChangeRequest extends Model
{
    use HasPrefixedUlid;

    public const ID_PREFIX = 'pcr';

    protected $fillable = ['account_id', 'workspace_id', 'user_id', 'from_plan', 'to_plan', 'note', 'status', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
