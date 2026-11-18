<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cat';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }
}
