<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Supresión por canal e índice ciego del correo o teléfono (modelo-de-datos.md
 * §3): rebote, queja, baja, registro de exclusión (RNE, REPEP), manual o
 * bloqueo de WhatsApp. SendPolicy la consulta antes de cada envío.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $channel
 * @property string $value_bidx
 * @property string $reason
 * @property CarbonInterface|null $created_at
 */
class Suppression extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    public const REASONS = ['bounce', 'complaint', 'unsubscribe', 'rne', 'repep', 'manual', 'wa_block'];

    protected $fillable = ['workspace_id', 'channel', 'value_bidx', 'reason'];

    /** Agrega la supresión si no existe (una por canal y valor; gana la primera razón). */
    public static function add(string $workspaceId, string $channel, string $bidx, string $reason): bool
    {
        return self::query()->insertOrIgnore([
            'workspace_id' => $workspaceId, 'channel' => $channel, 'value_bidx' => $bidx, 'reason' => $reason, 'created_at' => now(),
        ]) > 0;
    }
}
