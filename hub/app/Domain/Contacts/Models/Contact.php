<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una persona (modelo-de-datos.md §2). Correo, teléfono y nombres cifrados,
 * con índice ciego para buscarlos.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $email
 * @property string|null $email_bidx
 * @property string|null $phone
 * @property string|null $phone_bidx
 * @property string|null $first_name
 * @property string|null $last_name
 * @property list<string>|null $name_prefix_bidx
 * @property CarbonInterface|null $first_seen_at
 * @property CarbonInterface|null $last_seen_at
 */
class Contact extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid, SoftDeletes;

    public const ID_PREFIX = 'ct';

    protected $guarded = [];

    protected $hidden = ['email', 'phone', 'first_name', 'last_name', 'email_bidx', 'phone_bidx', 'name_prefix_bidx'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'name_prefix_bidx' => 'array',
            'first_source' => 'array',
            'first_order_source' => 'array',
            'custom_attributes' => 'array',
            'tags' => 'array',
            'birthday' => 'date',
            'renewal_at' => 'date',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
            'rfm_computed_at' => 'datetime',
            'expected_next_order_at' => 'datetime',
            'category_affinity' => 'array',
        ];
    }

    /** @return HasMany<ContactIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function displayName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }
}
