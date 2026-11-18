<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Recorrido de un contacto por un flujo (hub/CLAUDE.md §7).
 *
 * `context` guarda los datos del disparador sin datos personales (`trigger`,
 * `event_id`, `props`), lo que los nodos van produciendo (`cupon`), el intento
 * lógico de cada nodo (`_attempt`) y las marcas internas (`_woken`, `_goal`, `_test`).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $flow_id
 * @property string $flow_version_id
 * @property string $contact_id
 * @property string $status active | waiting | completed | exited | failed
 * @property string|null $current_node_id
 * @property CarbonInterface|null $next_run_at
 * @property array<string, mixed>|null $context
 * @property string|null $trigger_key
 * @property string|null $waiting_for
 * @property bool $is_holdout
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $ended_at
 * @property string|null $exit_reason
 */
class FlowRun extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'run';

    public const ACTIVE = 'active';

    public const WAITING = 'waiting';

    public const COMPLETED = 'completed';

    public const EXITED = 'exited';

    public const FAILED = 'failed';

    /** Estados en los que el run sigue vivo. */
    public const LIVE = [self::ACTIVE, self::WAITING];

    protected $fillable = [
        'workspace_id', 'flow_id', 'flow_version_id', 'contact_id', 'status', 'current_node_id', 'next_run_at', 'context',
        'trigger_key', 'waiting_for', 'is_holdout', 'started_at', 'ended_at', 'exit_reason',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'is_holdout' => 'boolean',
            'next_run_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isTest(): bool
    {
        return ! empty($this->context['_test']);
    }

    /** Intento lógico del nodo en esta visita (sube si un ciclo vuelve al nodo). */
    public function attemptFor(string $nodeId): int
    {
        return max(1, (int) ($this->context['_attempt'][$nodeId] ?? 1));
    }
}
