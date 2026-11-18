<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Models;

use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Un destinatario de una campaña (modelo-de-datos.md §12): variante, grupo de
 * control y estado. Estados:
 *
 * - `pending`: espera su hora (`scheduled_for`).
 * - `held`: A/B, espera a que se elija la variante ganadora.
 * - `deferred`: SendPolicy lo aplazó; vuelve a intentarse en `scheduled_for`.
 * - `sent`: el despachador lo encoló (`message_id`).
 * - `denied`: SendPolicy no lo permite (`reason`).
 * - `skipped`: grupo de control o campaña cancelada (`reason`).
 * - `failed`: error al despachar (`reason`).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property string|null $variant
 * @property bool $is_holdout
 * @property string $status
 * @property string|null $reason
 * @property CarbonInterface|null $scheduled_for
 * @property string|null $message_id
 */
class CampaignRecipient extends Model
{
    use BelongsToWorkspace;

    public const PENDING = 'pending';

    public const HELD = 'held';

    public const DEFERRED = 'deferred';

    public const SENT = 'sent';

    public const DENIED = 'denied';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = ['workspace_id', 'campaign_id', 'contact_id', 'variant', 'is_holdout', 'status', 'reason', 'scheduled_for', 'message_id'];

    protected function casts(): array
    {
        return [
            'is_holdout' => 'boolean',
            'scheduled_for' => 'datetime',
        ];
    }
}
