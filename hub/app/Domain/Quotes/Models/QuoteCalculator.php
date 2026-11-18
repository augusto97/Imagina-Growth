<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Calculadora del cotizador (M18): por servicio, por área (m², metros, horas) o por
 * paquetes. `definition` la normaliza `QuoteEngine::normalize()`; `capture` dice qué
 * datos pide antes de mostrar el precio y con qué casillas de consentimiento.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $site_id
 * @property string $name
 * @property string $kind service | area | package
 * @property array<string, mixed> $definition
 * @property array<string, mixed>|null $capture
 * @property string $status draft | active | paused
 */
class QuoteCalculator extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'qc';

    public const KINDS = ['service', 'area', 'package'];

    public const STATUSES = ['draft', 'active', 'paused'];

    protected $fillable = ['workspace_id', 'site_id', 'name', 'kind', 'definition', 'capture', 'status'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'capture' => 'array'];
    }
}
