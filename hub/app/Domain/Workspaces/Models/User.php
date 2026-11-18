<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Models;

use App\Support\Ids\HasPrefixedUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property bool $is_platform_admin
 * @property Carbon|null $two_factor_confirmed_at
 * @property-read Pivot|null $pivot rol en workspace_user o account_user
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPrefixedUlid, Notifiable, TwoFactorAuthenticatable;

    public const ID_PREFIX = 'usr';

    protected $fillable = ['name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /** @return BelongsToMany<Account, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)->withPivot('role')->withTimestamps();
    }

    public function hasTwoFactorEnabled(): bool
    {
        // Lee el arreglo crudo: un modelo recién creado en memoria puede no traer estas columnas.
        return ($this->attributes['two_factor_confirmed_at'] ?? null) !== null
            && ($this->attributes['two_factor_secret'] ?? null) !== null;
    }
}
