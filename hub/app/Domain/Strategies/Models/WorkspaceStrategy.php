<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que la persona decidió con una estrategia (docs/diseno/rediseno-v2.md §6): activa o en pausa, quién y cuándo, y
 * qué bloques creó o tocó. El estado real se lee de los bloques (StrategyBlock::state); esta fila es la intención y
 * la memoria para reanudar.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $strategy_key
 * @property string $status active | paused
 * @property array<string, mixed>|null $refs {flows: list<string>, widgets: list<string>, …}
 * @property array<string, mixed>|null $settings valores para reanudar
 * @property CarbonInterface|null $activated_at
 * @property string|null $activated_by
 * @property CarbonInterface|null $paused_at
 * @property string|null $paused_by
 */
class WorkspaceStrategy extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'wst';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    protected $fillable = ['workspace_id', 'strategy_key', 'status', 'refs', 'settings', 'activated_at', 'activated_by', 'paused_at', 'paused_by'];

    protected function casts(): array
    {
        return ['refs' => 'array', 'settings' => 'array', 'activated_at' => 'datetime', 'paused_at' => 'datetime'];
    }

    /** @return list<string> */
    public function refList(string $kind): array
    {
        return array_values(array_map('strval', (array) (($this->refs ?? [])[$kind] ?? [])));
    }
}
