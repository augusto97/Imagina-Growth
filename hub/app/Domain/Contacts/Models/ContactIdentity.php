<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property CarbonInterface|null $last_seen_at
 * @property string $contact_id
 * @property string $type
 * @property string $value
 */
class ContactIdentity extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cid';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
