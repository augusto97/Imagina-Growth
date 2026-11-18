<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Historia de una conversación (y la base de las métricas por agente):
 * `opened`, `assigned`, `unassigned`, `status`, `label`, `ai_mode`, `handoff`,
 * `message` (un envío de la bandeja con su autor), `first_response`, `note` y
 * `sale`. `actor` es el ID del usuario, `ai` o `system`. Sin datos personales.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $conversation_id
 * @property string $kind
 * @property string $actor
 * @property array<string, mixed>|null $data
 * @property CarbonInterface $created_at
 */
class ConversationEvent extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected $fillable = ['workspace_id', 'conversation_id', 'kind', 'actor', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /** @param array<string, mixed> $data */
    public static function record(string $workspaceId, string $conversationId, string $kind, string $actor, array $data = []): self
    {
        return self::query()->create([
            'workspace_id' => $workspaceId, 'conversation_id' => $conversationId, 'kind' => $kind,
            'actor' => mb_substr($actor, 0, 32), 'data' => $data === [] ? null : $data, 'created_at' => now(),
        ]);
    }
}
