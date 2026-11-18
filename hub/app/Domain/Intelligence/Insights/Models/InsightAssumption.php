<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Un supuesto del impacto que la comerciante cambió (inteligencia.md §5.2).
 *
 * @property string $workspace_id
 * @property string $key
 * @property string $value
 * @property string|null $updated_by
 */
class InsightAssumption extends Model
{
    use BelongsToWorkspace;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['workspace_id', 'key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'decimal:6'];
    }
}
