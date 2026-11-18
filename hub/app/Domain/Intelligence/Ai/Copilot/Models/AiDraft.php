<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Propuesta del copiloto (campaña o flujo) o contenido generado
 * (inteligencia.md §6.4–6.5). Siempre espera aprobación humana: aplicarla crea
 * **borradores** con las acciones normales del módulo, nunca envía ni activa.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $user_id
 * @property string $kind campaign | flow | content
 * @property string $prompt
 * @property array<string, mixed> $proposal
 * @property string $status draft | applied | discarded
 * @property string|null $applied_type campaign | flow | template
 * @property string|null $applied_id
 * @property string|null $model
 * @property array<string, mixed>|null $validation
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AiDraft extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'aid';

    public const DRAFT = 'draft';

    public const APPLIED = 'applied';

    public const DISCARDED = 'discarded';

    protected $table = 'ai_drafts';

    protected $fillable = ['workspace_id', 'user_id', 'kind', 'prompt', 'proposal', 'status', 'applied_type', 'applied_id', 'model', 'validation'];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'validation' => 'array'];
    }
}
