<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Nota interna de una conversación (no la ve el cliente). El texto va cifrado.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $conversation_id
 * @property string|null $user_id
 * @property string $body
 */
class ConversationNote extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cnn';

    protected $fillable = ['workspace_id', 'conversation_id', 'user_id', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }
}
