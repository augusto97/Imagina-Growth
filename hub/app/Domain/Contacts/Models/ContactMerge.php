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
 * @property string $winner_id
 * @property string $loser_id
 * @property string $reason
 * @property array<string, mixed>|null $snapshot
 * @property CarbonInterface|null $reverted_at
 * @property CarbonInterface|null $created_at
 */
class ContactMerge extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'mrg';

    public const REVERT_DAYS = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'reverted_at' => 'datetime'];
    }

    public function canRevert(): bool
    {
        return $this->reverted_at === null && $this->created_at !== null && $this->created_at->greaterThan(now()->subDays(self::REVERT_DAYS));
    }
}
