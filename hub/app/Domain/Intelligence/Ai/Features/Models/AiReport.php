<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Informe del analista con IA (inteligencia.md §6.2): uno por semana y espacio de trabajo.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $kind
 * @property CarbonInterface $period_start
 * @property CarbonInterface $period_end
 * @property array<string, mixed> $facts
 * @property string $body_markdown
 * @property array<int, array<string, mixed>>|null $actions
 * @property string $source
 * @property string|null $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property array<string, mixed>|null $validation
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AiReport extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'air';

    protected $table = 'ai_reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'actions' => 'array',
            'validation' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'delivered_at' => 'datetime',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
        ];
    }
}
