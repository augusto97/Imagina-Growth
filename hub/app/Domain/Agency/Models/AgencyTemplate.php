<?php

declare(strict_types=1);

namespace App\Domain\Agency\Models;

use App\Support\Ids\HasPrefixedUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla maestra de la agencia (M22): un flujo, segmento, widget, plantilla o
 * campaña en forma portable (`Portable\PortableExporter`), sin IDs de un espacio
 * de trabajo ni datos de contactos.
 *
 * @property string $id
 * @property string $account_id
 * @property string $kind
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $payload
 * @property string|null $source_workspace_id
 * @property string|null $created_by
 */
class AgencyTemplate extends Model
{
    use HasPrefixedUlid;

    public const ID_PREFIX = 'atp';

    protected $fillable = ['account_id', 'kind', 'name', 'description', 'payload', 'source_workspace_id', 'created_by'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
