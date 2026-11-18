<?php

declare(strict_types=1);

namespace App\Domain\Segments\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Pertenencia de un contacto a un segmento. Clave primaria compuesta
 * (segment_id, contact_id): se escribe con el query builder, no con save().
 *
 * @property string $segment_id
 * @property string $contact_id
 * @property string $workspace_id
 * @property CarbonInterface $entered_at
 */
class SegmentMembership extends Model
{
    use BelongsToWorkspace;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'segment_id';

    protected $keyType = 'string';

    protected $fillable = ['segment_id', 'contact_id', 'workspace_id', 'entered_at'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }
}
