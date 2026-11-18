<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un intento de entrega de un webhook. Cada reintento es una fila nueva (`attempt` + 1), así
 * el registro muestra la historia completa. El cuerpo enviado queda cifrado para reenviarlo.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $subscription_id
 * @property string $event
 * @property string $event_id
 * @property string|null $payload
 * @property int $attempt
 * @property string $kind event | retry | replay | ping
 * @property int|null $status_code
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon|null $next_attempt_at
 * @property Carbon $created_at
 */
class WebhookDelivery extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'subscription_id', 'event', 'event_id', 'payload', 'attempt', 'kind', 'status_code', 'duration_ms', 'error', 'next_attempt_at', 'created_at'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'attempt' => 'integer',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function succeeded(): bool
    {
        return $this->status_code !== null && $this->status_code >= 200 && $this->status_code < 300;
    }

    /** pending (programado), sending, ok o failed */
    public function state(): string
    {
        if ($this->succeeded()) {
            return 'ok';
        }
        if ($this->status_code !== null || $this->error !== null) {
            return 'failed';
        }

        return $this->next_attempt_at !== null ? 'pending' : 'sending';
    }
}
