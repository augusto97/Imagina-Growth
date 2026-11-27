<?php

declare(strict_types=1);

namespace App\Domain\Notices\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué se le avisó a quién por una cita o un lead (lote 0.4.0, docs/avisos.md): canal, estado y, si no salió, el motivo.
 * Alimenta la sección «Avisos» de la cita y del lead. `recipient` cifrado (correo enmascarado o nombre de la persona).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $subject_type appointment | lead
 * @property string $subject_id
 * @property string $audience customer | team
 * @property string $event
 * @property string|null $channel whatsapp | email | in_app | push
 * @property string|null $recipient
 * @property string $status queued | sent | deferred | denied | skipped | failed
 * @property string|null $reason
 * @property string|null $message_id
 * @property string|null $user_id
 * @property CarbonInterface|null $created_at
 */
class NoticeLog extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'nlg';

    public const UPDATED_AT = null;

    protected $table = 'notice_log';

    protected $fillable = ['workspace_id', 'subject_type', 'subject_id', 'audience', 'event', 'channel', 'recipient', 'status', 'reason', 'message_id', 'user_id'];

    protected function casts(): array
    {
        return ['recipient' => 'encrypted'];
    }
}
