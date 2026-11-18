<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de un titular (habeas data / ARCO, cumplimiento.md §9).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $contact_id
 * @property string $kind
 * @property string|null $requester
 * @property string $status
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $completed_at
 * @property array<string, mixed>|null $evidence
 */
class DataRequest extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'dr';

    protected $guarded = [];

    protected $hidden = ['requester'];

    protected function casts(): array
    {
        return ['requester' => 'encrypted', 'evidence' => 'array', 'due_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
