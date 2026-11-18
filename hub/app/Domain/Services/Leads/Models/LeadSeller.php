<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quien atiende leads: un usuario del equipo con su WhatsApp (cifrado) para el
 * aviso al instante y las palabras de los servicios que atiende.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string|null $phone
 * @property list<string>|null $services
 * @property bool $active
 * @property Carbon|null $last_assigned_at
 * @property int|null $last_assigned_seq
 */
class LeadSeller extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'lsl';

    protected $fillable = ['workspace_id', 'user_id', 'phone', 'services', 'active', 'last_assigned_at', 'last_assigned_seq'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'services' => 'array', 'active' => 'boolean', 'last_assigned_at' => 'datetime', 'last_assigned_seq' => 'integer'];
    }
}
