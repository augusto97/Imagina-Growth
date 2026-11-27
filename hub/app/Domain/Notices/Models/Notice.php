<?php

declare(strict_types=1);

namespace App\Domain\Notices\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso del centro de avisos de la app (lote 0.4.0, docs/avisos.md): para una persona en un espacio de trabajo.
 * Título y detalle cifrados (pueden llevar el nombre del contacto).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $kind
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $created_at
 */
class Notice extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ntc';

    protected $fillable = ['workspace_id', 'user_id', 'kind', 'title', 'body', 'url', 'subject_type', 'subject_id', 'read_at'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'body' => 'encrypted', 'read_at' => 'datetime'];
    }
}
