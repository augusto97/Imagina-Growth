<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Models;

use App\Domain\Workspaces\Enums\AccountType;
use App\Support\Ids\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quien paga: la agencia o un cliente directo. No pertenece a un espacio de trabajo.
 *
 * @property string $id
 * @property AccountType $type
 * @property string $name
 */
class Account extends Model
{
    use HasPrefixedUlid;

    public const ID_PREFIX = 'acc';

    protected $fillable = ['type', 'name', 'legal_name', 'tax_id', 'country', 'billing_email', 'plan_id', 'status'];

    protected function casts(): array
    {
        return ['type' => AccountType::class];
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')->withPivot('role')->withTimestamps();
    }
}
