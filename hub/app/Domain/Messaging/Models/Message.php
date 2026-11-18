<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje enviado o recibido (modelo-de-datos.md §5). `payload` va cifrado:
 * destino, asunto y contenido renderizado (para el job de envío y la auditoría).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $contact_id
 * @property string|null $channel_account_id
 * @property Channel $channel
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string $direction
 * @property MessageKind $kind
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $flow_id
 * @property string|null $campaign_id
 * @property string|null $template_id
 * @property string|null $idempotency_key
 * @property string $status
 * @property string|null $policy_reason
 * @property CarbonInterface|null $deferred_until
 * @property string|null $cost_estimated
 * @property string|null $cost_currency
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $clicked_at
 * @property CarbonInterface|null $replied_at
 * @property string|null $failed_reason
 * @property string|null $error_code
 * @property string|null $conversation_id
 * @property CarbonInterface|null $created_at
 */
class Message extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'msg';

    /** Estados finales: un webhook tardío no los devuelve atrás. */
    public const FINAL = ['failed', 'bounced'];

    /** Orden de avance de los estados de entrega. */
    public const PROGRESS = ['queued' => 1, 'deferred' => 1, 'sending' => 2, 'sent' => 3, 'delivered' => 4, 'read' => 5];

    protected $fillable = [
        'workspace_id', 'contact_id', 'channel_account_id', 'channel', 'provider', 'provider_message_id', 'direction', 'kind',
        'source_type', 'source_id', 'flow_id', 'campaign_id', 'template_id', 'idempotency_key', 'status', 'policy_reason',
        'deferred_until', 'cost_estimated', 'cost_currency', 'payload', 'queued_at', 'sent_at', 'delivered_at', 'read_at',
        'opened_at', 'clicked_at', 'replied_at', 'failed_reason', 'error_code', 'conversation_id',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'kind' => MessageKind::class,
            'payload' => 'encrypted:array',
            'deferred_until' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    /**
     * Propiedades comunes de los eventos message.* (eventos.md §4.6).
     *
     * @return array<string, string>
     */
    public function eventProperties(): array
    {
        return array_filter([
            'message_id' => $this->publicId(),
            'channel' => $this->channel->value,
            'kind' => $this->kind->value,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'flow_id' => $this->flow_id,
            'campaign_id' => $this->campaign_id,
            'template_id' => $this->template_id,
            'provider' => $this->provider,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
