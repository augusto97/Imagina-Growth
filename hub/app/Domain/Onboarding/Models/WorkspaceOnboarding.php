<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * El asistente de configuración de un espacio de trabajo (docs/diseno/rediseno-v2.md §4): el tipo de negocio que
 * eligió la persona, el paso donde quedó y si lo saltó o lo terminó. Una fila por espacio de trabajo; sin fila, el
 * asistente no ha empezado. Los pasos hechos no se guardan: se derivan de los datos (OnboardingState).
 *
 * @property string $workspace_id
 * @property string|null $business_type store | services | both
 * @property string|null $current_step
 * @property CarbonInterface|null $skipped_at
 * @property CarbonInterface|null $completed_at
 * @property string|null $updated_by
 */
class WorkspaceOnboarding extends Model
{
    use BelongsToWorkspace;

    public const BUSINESS_TYPES = ['store', 'services', 'both'];

    public const STEPS = ['business', 'connect', 'history', 'channels', 'strategies', 'done'];

    protected $table = 'workspace_onboarding';

    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['workspace_id', 'business_type', 'current_step', 'skipped_at', 'completed_at', 'updated_by'];

    protected function casts(): array
    {
        return ['skipped_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
