<?php

declare(strict_types=1);

namespace App\Domain\Capture\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un chat que llegó con el código de un enlace de WhatsApp: quién (contacto), qué mensaje, si era nuevo, el lead y la
 * pregunta de consentimiento con botones (cuándo se hizo y cuándo aceptó). Uno por enlace y mensaje.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $link_id
 * @property string $contact_id
 * @property string $message_id
 * @property bool $was_new
 * @property string|null $lead_id
 * @property CarbonInterface|null $consent_asked_at
 * @property CarbonInterface|null $consent_granted_at
 * @property CarbonInterface $created_at
 */
class WhatsAppLinkHit extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $table = 'whatsapp_link_hits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'was_new' => 'boolean',
            'consent_asked_at' => 'datetime',
            'consent_granted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
