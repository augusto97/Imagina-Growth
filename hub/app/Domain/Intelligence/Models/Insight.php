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
 * @property CarbonInterface|null $snoozed_until
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Insight extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ins';

    public const SEVERITY_ORDER = ['critical' => 4, 'warning' => 3, 'opportunity' => 2, 'info' => 1];

    protected $guarded = [];

    /** Cuántos días se puede posponer una recomendación desde el panel. */
    public const SNOOZE_DAYS = [1, 3, 7, 30];

    /**
     * Las pospuestas cuya fecha ya pasó vuelven a abiertas («vista»). Se llama al leer la lista, el resumen de
     * Resultados y al correr los detectores; con el índice (workspace_id, status, …) es una sola actualización barata.
     */
    public static function wakeSnoozed(): int
    {
        return static::query()->where('status', 'snoozed')->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->update(['status' => 'seen', 'snoozed_until' => null, 'updated_at' => now()]);
    }

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'impact_assumptions' => 'array',
            'recommended_action' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'dismissed_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
