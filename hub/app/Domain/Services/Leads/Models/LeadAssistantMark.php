<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lo que el vendedor marcó en «Hoy»: hecho o pospuesto hasta una fecha. La clave
 * lleva el momento de la situación (`reply:{lead}:{desde}`), así un mensaje nuevo
 * del cliente o un cambio de etapa vuelve a sacar el lead en la lista.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $item_key
 * @property string|null $lead_id
 * @property string $status done | snoozed
 * @property Carbon|null $until
 * @property string|null $user_id
 */
class LeadAssistantMark extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'item_key', 'lead_id', 'status', 'until', 'user_id'];

    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }
}
