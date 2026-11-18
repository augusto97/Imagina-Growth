<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * La historia de un lead. Las notas guardan el texto cifrado en `data.body`.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $lead_id
 * @property string $kind created | stage | note | score | alert | owner | message | value
 * @property string $actor usuario | system | ai
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
class LeadActivity extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'lead_id', 'kind', 'actor', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /** @param array<string, mixed> $data */
    public static function log(Lead $lead, string $kind, string $actor, array $data = []): self
    {
        return self::query()->create([
            'workspace_id' => $lead->workspace_id, 'lead_id' => $lead->id, 'kind' => $kind, 'actor' => mb_substr($actor, 0, 32),
            'data' => $data === [] ? null : $data, 'created_at' => now(),
        ]);
    }
}
