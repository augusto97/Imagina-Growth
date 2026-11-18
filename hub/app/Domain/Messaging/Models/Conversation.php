<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Conversación de WhatsApp de un contacto con un número (la bandeja llega en la
 * Fase 4). En la Fase 2 sirve para la ventana de 24 h: cada mensaje entrante la
 * abre por 24 horas más.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $contact_id
 * @property string $channel_account_id
 * @property string $status
 * @property CarbonInterface|null $window_expires_at
 * @property CarbonInterface|null $last_inbound_at
 * @property CarbonInterface|null $last_outbound_at
 */
class Conversation extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cnv';

    protected $fillable = ['workspace_id', 'contact_id', 'channel_account_id', 'status', 'assignee_id', 'labels', 'ai_mode', 'window_expires_at', 'last_inbound_at', 'last_outbound_at', 'first_response_seconds'];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'window_expires_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }
}
