<?php

declare(strict_types=1);

namespace App\Domain\Consent\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado vigente del consentimiento por contacto, canal y finalidad
 * (modelo-de-datos.md §3). El historial inmutable está en consent_events.
 *
 * @property string $id
 * @property string $contact_id
 * @property string $channel
 * @property string $purpose
 * @property string $status
 * @property string $source
 * @property string|null $legal_text_version
 * @property CarbonInterface|null $granted_at
 * @property CarbonInterface|null $revoked_at
 */
class ContactConsent extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'cns';

    public const CHANNELS = ['email', 'whatsapp', 'sms', 'push', 'ads'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Momento de la última acción (otorgar o revocar). */
    public function lastActionAt(): ?CarbonInterface
    {
        if ($this->granted_at === null) {
            return $this->revoked_at;
        }
        if ($this->revoked_at === null) {
            return $this->granted_at;
        }

        return $this->granted_at->greaterThan($this->revoked_at) ? $this->granted_at : $this->revoked_at;
    }
}
