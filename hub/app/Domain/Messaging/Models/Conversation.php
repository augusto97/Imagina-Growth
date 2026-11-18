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
 * @property string|null $assignee_id
 * @property list<string>|null $labels
 * @property string $ai_mode
 * @property int $unread_count
 * @property CarbonInterface|null $last_message_at
 * @property CarbonInterface|null $resolved_at
 * @property int|null $first_response_seconds
 * @property string|null $intent
 * @property string|null $handoff_reason
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $awaiting_since
 */
class Conversation extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cnv';

    protected $fillable = ['workspace_id', 'contact_id', 'channel_account_id', 'status', 'assignee_id', 'labels', 'ai_mode', 'window_expires_at', 'last_inbound_at', 'last_outbound_at', 'first_response_seconds',
        'unread_count', 'last_message_at', 'resolved_at', 'intent', 'handoff_reason', 'opened_at', 'awaiting_since'];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'window_expires_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
            'opened_at' => 'datetime',
            'awaiting_since' => 'datetime',
            'unread_count' => 'integer',
            'first_response_seconds' => 'integer',
        ];
    }
}
