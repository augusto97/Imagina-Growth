<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'oi';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['category_ids' => 'array'];
    }
}
