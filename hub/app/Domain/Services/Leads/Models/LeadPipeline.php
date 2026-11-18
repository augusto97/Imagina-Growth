<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Pipeline de leads: etapas con su tipo (`open`, `won`, `lost`). El simple (nuevo →
 * contactado → calificado → propuesta → ganado o perdido) se crea solo.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property list<array{key: string, label: string, kind: string}> $stages
 * @property bool $is_default
 */
class LeadPipeline extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lpl';

    protected $fillable = ['workspace_id', 'name', 'stages', 'is_default'];

    protected function casts(): array
    {
        return ['stages' => 'array', 'is_default' => 'boolean'];
    }

    /** @return array{key: string, label: string, kind: string}|null */
    public function stage(string $key): ?array
    {
        foreach ($this->stages as $stage) {
            if ($stage['key'] === $key) {
                return $stage;
            }
        }

        return null;
    }

    public function kindOf(string $key): string
    {
        return $this->stage($key)['kind'] ?? 'open';
    }
}
