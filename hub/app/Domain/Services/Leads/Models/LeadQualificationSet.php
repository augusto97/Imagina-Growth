<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Preguntas de calificación que el agente de ventas hace en el chat (M18, auditoría
 * §5.1 función 3), por pipeline o por servicio, con sus reglas de derivación. La forma
 * de `questions` y `routing` la valida y normaliza `Qualification\QualificationRules`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $pipeline_id
 * @property list<string>|null $services
 * @property bool $active
 * @property list<array<string, mixed>> $questions
 * @property array<string, mixed> $routing
 * @property int $position
 */
class LeadQualificationSet extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lqs';

    protected $fillable = ['workspace_id', 'name', 'pipeline_id', 'services', 'active', 'questions', 'routing', 'position'];

    protected function casts(): array
    {
        return ['services' => 'array', 'active' => 'boolean', 'questions' => 'array', 'routing' => 'array', 'position' => 'integer'];
    }
}
