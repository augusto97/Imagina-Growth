<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $external_id
 * @property string $content_hash
 * @property CarbonInterface|null $source_updated_at
 */
class Product extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'prd';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'source_updated_at' => 'datetime', 'deleted_at' => 'datetime'];
    }
}
