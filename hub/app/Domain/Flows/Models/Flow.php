<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Automatización (modulos.md M09, hub/CLAUDE.md §7). El disparador, la meta,
 * la reentrada, las exclusiones y el grupo de control son del flujo; el grafo
 * es de cada versión (FlowVersion). `current_version_id` es la versión
 * publicada con la que entran los runs nuevos.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property string $status draft | active | paused | archived
 * @property array<string, mixed> $trigger
 * @property string|null $current_version_id
 * @property string|null $template_key
 * @property array<string, mixed>|null $goal
 * @property array<string, mixed>|null $reentry_policy
 * @property list<string>|null $exclusions IDs internos de flujos excluyentes
 * @property int $holdout_pct
 * @property array<string, mixed>|null $test_recipients {user_ids: list<string>} (sin datos personales)
 * @property array<string, mixed>|null $stats
 * @property string|null $created_by
 * @property CarbonInterface|null $activated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Flow extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'fl';

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'workspace_id', 'name', 'description', 'status', 'trigger', 'current_version_id', 'template_key', 'goal',
        'reentry_policy', 'exclusions', 'holdout_pct', 'test_recipients', 'stats', 'created_by', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => 'array',
            'goal' => 'array',
            'reentry_policy' => 'array',
            'exclusions' => 'array',
            'test_recipients' => 'array',
            'stats' => 'array',
            'holdout_pct' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    /** @return HasMany<FlowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): ?FlowVersion
    {
        return $this->current_version_id === null ? null : FlowVersion::query()->find($this->current_version_id);
    }

    /** La versión en edición (sin publicar), si hay. */
    public function draftVersion(): ?FlowVersion
    {
        return FlowVersion::query()->where('flow_id', $this->id)->whereNull('published_at')->orderByDesc('version')->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Evento del disparador (los de segmento se traducen a segment.entered/exited). */
    public function triggerEvent(): ?string
    {
        $type = $this->trigger['type'] ?? null;

        return match ($type) {
            'event' => is_string($this->trigger['event'] ?? null) ? $this->trigger['event'] : null,
            'segment' => ($this->trigger['on'] ?? 'entered') === 'exited' ? 'segment.exited' : 'segment.entered',
            default => null,
        };
    }

    public function goalEvent(): ?string
    {
        $event = $this->goal['event'] ?? null;

        return is_string($event) && $event !== '' ? $event : null;
    }
}
