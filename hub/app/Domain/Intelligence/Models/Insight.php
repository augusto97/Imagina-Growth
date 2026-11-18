<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Hallazgo (inteligencia.md §5).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $detector_key
 * @property string $severity
 * @property string $title
 * @property array<string, mixed> $facts
 * @property string|null $estimated_impact_ws
 * @property array<string, mixed>|null $impact_assumptions
 * @property array<string, mixed>|null $recommended_action
 * @property string $status
 * @property string|null $fingerprint
 * @property CarbonInterface|null $dismissed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Insight extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ins';

    public const SEVERITY_ORDER = ['critical' => 4, 'warning' => 3, 'opportunity' => 2, 'info' => 1];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'impact_assumptions' => 'array',
            'recommended_action' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'dismissed_at' => 'datetime',
        ];
    }
}
