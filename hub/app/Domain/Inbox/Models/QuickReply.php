<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Respuesta rápida de la bandeja: se inserta escribiendo `/atajo` en el chat.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $shortcut
 * @property string $title
 * @property string $body
 * @property string|null $created_by
 */
class QuickReply extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'qr';

    protected $fillable = ['workspace_id', 'shortcut', 'title', 'body', 'created_by'];
}
