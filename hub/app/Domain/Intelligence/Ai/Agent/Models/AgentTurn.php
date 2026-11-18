<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un turno del agente de ventas (inteligencia.md §6.7): qué respondió o sugirió,
 * con qué herramientas, la validación de sus cifras y la revisión humana.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $conversation_id
 * @property string|null $inbound_message_id
 * @property string $mode assist | auto
 * @property bool $is_test
 * @property string $status replied | suggested | handoff | blocked | failed
 * @property string|null $handoff_reason
 * @property string|null $reply_message_id
 * @property string|null $cart_id
 * @property string|null $suggestion
 * @property list<array<string, mixed>>|null $tool_calls
 * @property array<string, mixed>|null $validation
 * @property string|null $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int|null $latency_ms
 * @property bool $flagged
 * @property string|null $review_status ok | wrong | needs_fix
 * @property string|null $reviewed_by
 * @property Carbon|null $created_at
 */
class AgentTurn extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'agt';

    protected $table = 'ai_agent_turns';

    protected $fillable = [
        'workspace_id', 'conversation_id', 'inbound_message_id', 'mode', 'is_test', 'status', 'handoff_reason', 'reply_message_id', 'cart_id',
        'suggestion', 'tool_calls', 'validation', 'model', 'tokens_in', 'tokens_out', 'latency_ms', 'flagged', 'review_status', 'reviewed_by',
    ];

    protected $hidden = ['suggestion'];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'suggestion' => 'encrypted',
            'tool_calls' => 'array',
            'validation' => 'array',
            'flagged' => 'boolean',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'latency_ms' => 'integer',
        ];
    }
}
