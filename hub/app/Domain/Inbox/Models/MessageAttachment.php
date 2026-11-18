<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Adjunto de un mensaje (imagen, audio, video, documento, sticker). Por ahora se
 * guardan los metadatos y el ID del proveedor; la descarga del archivo desde
 * Meta queda pendiente [verificar].
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $message_id
 * @property string $kind
 * @property string|null $mime
 * @property string|null $provider_media_id
 * @property string|null $caption
 */
class MessageAttachment extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'att';

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'message_id', 'kind', 'mime', 'size', 'disk_path', 'provider_media_id', 'caption', 'created_at'];

    protected function casts(): array
    {
        return ['caption' => 'encrypted'];
    }
}
