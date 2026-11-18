<?php

declare(strict_types=1);

namespace App\Domain\Segments\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Segmento de contactos (modulos.md M04, hub/CLAUDE.md §6).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed>|null $definition AST; null en los estáticos
 * @property string $kind dynamic | static
 * @property bool $is_system
 * @property string|null $system_key
 * @property int $member_count
 * @property string $revenue_ws
 * @property array<string, int>|null $channel_counts
 * @property CarbonInterface|null $last_computed_at
 * @property string|null $folder
 * @property string|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Segment extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid, SoftDeletes;

    public const ID_PREFIX = 'sg';

    public const KIND_DYNAMIC = 'dynamic';

    public const KIND_STATIC = 'static';

    protected $fillable = [
        'workspace_id', 'name', 'description', 'definition', 'kind', 'is_system', 'system_key',
        'member_count', 'revenue_ws', 'channel_counts', 'last_computed_at', 'folder', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_system' => 'boolean',
            'member_count' => 'integer',
            'channel_counts' => 'array',
            'last_computed_at' => 'datetime',
        ];
    }

    public function isDynamic(): bool
    {
        return $this->kind === self::KIND_DYNAMIC && $this->definition !== null;
    }

    /** @return HasMany<SegmentMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(SegmentMembership::class);
    }
}
