<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Models;

use App\Domain\Sites\Models\Site;
use App\Support\Ids\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un negocio. Es la raíz de la tenancy: no usa BelongsToWorkspace.
 *
 * @property string $id
 * @property int $ch_id
 * @property string $account_id
 * @property string $name
 * @property string $slug
 * @property string $country
 * @property string $timezone
 * @property string $currency
 * @property string $locale
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $brand_kit
 * @property string $status
 */
class Workspace extends Model
{
    use HasPrefixedUlid;

    public const ID_PREFIX = 'ws';

    protected $fillable = [
        'account_id', 'name', 'slug', 'country', 'timezone', 'currency', 'locale', 'industry',
        'legal_name', 'privacy_policy_url', 'settings', 'brand_kit', 'status',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array', 'brand_kit' => 'array', 'ch_id' => 'integer'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
