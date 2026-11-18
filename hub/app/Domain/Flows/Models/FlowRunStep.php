<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Paso ejecutado de un run. Único por (run_id, node_id, attempt): si el job se
 * reintenta, el paso ya registrado no repite su efecto (un envío nunca sale dos veces).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $run_id
 * @property string $node_id
 * @property int $attempt
 * @property string $outcome continue | wait | wait_event | deferred | sent | skipped_holdout | denied | failed | exit
 * @property array<string, mixed>|null $details
 * @property CarbonInterface $executed_at
 */
class FlowRunStep extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'run_id', 'node_id', 'attempt', 'outcome', 'details', 'executed_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'attempt' => 'integer', 'executed_at' => 'datetime'];
    }
}
