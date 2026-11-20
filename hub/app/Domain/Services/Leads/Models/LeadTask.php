<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una tarea o llamada del vendedor (M18, «Hoy»): la crea una persona desde el lead
 * o un flujo con el paso «Crear tarea» (`crm_task`). La nota va cifrada.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $lead_id
 * @property string|null $contact_id
 * @property string|null $owner_id
 * @property string $kind task | call
 * @property string $title
 * @property string|null $note
 * @property Carbon $due_at
 * @property string $status open | done
 * @property string $source user | flow
 * @property string|null $source_ref
 * @property string|null $created_by
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LeadTask extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ltk';

    public const KINDS = ['task', 'call'];

    protected $fillable = ['workspace_id', 'lead_id', 'contact_id', 'owner_id', 'kind', 'title', 'note', 'due_at', 'status', 'source', 'source_ref', 'created_by', 'done_at', 'done_by'];

    protected $hidden = ['note'];

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'due_at' => 'datetime', 'done_at' => 'datetime'];
    }
}
