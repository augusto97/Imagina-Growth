<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Importación de un registro de exclusión: RNE de la CRC (Colombia) o REPEP de
 * PROFECO (México), cumplimiento.md §4.2 y §6.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $country
 * @property string $file_hash
 * @property int $rows
 * @property int $added
 * @property string|null $imported_by
 * @property CarbonInterface $imported_at
 */
class ExcludedRegistryImport extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'country', 'file_hash', 'rows', 'added', 'imported_by', 'imported_at'];

    protected function casts(): array
    {
        return ['rows' => 'integer', 'added' => 'integer', 'imported_at' => 'datetime'];
    }
}
