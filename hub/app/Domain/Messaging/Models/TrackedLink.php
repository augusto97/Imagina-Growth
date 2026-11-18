<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Enlace rastreado de un mensaje (hub/CLAUDE.md §8). El código (8 caracteres
 * aleatorios) también es el `_igr` que identifica la visita en el sitio.
 *
 * @property string $code
 * @property string $workspace_id
 * @property string $message_id
 * @property string|null $contact_id
 * @property string $url
 * @property int $clicks
 * @property CarbonInterface|null $first_clicked_at
 * @property CarbonInterface|null $created_at
 */
class TrackedLink extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'workspace_id', 'message_id', 'contact_id', 'url', 'clicks', 'first_clicked_at'];

    protected function casts(): array
    {
        return ['clicks' => 'integer', 'first_clicked_at' => 'datetime'];
    }
}
