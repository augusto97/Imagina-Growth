<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Cita (M18). `reminders`: `{"24h": ISO, "2h": ISO, "confirmation": ISO}` (cuándo
 * se envió cada mensaje). `manage_token` firma el enlace para reprogramar o
 * cancelar sin iniciar sesión.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $contact_id
 * @property string $service_id
 * @property string|null $staff_id
 * @property string|null $lead_id
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $status booked | confirmed | rescheduled | cancelled | completed | no_show
 * @property string $channel page | inbox | agent | manual | api
 * @property string|null $manage_token
 * @property string|null $external_calendar_event_id
 * @property array<string, string>|null $reminders
 * @property string|null $notes
 */
class Appointment extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ap';

    /** Estados que ocupan el horario. */
    public const ACTIVE = ['booked', 'confirmed', 'rescheduled'];

    public const STATUSES = ['booked', 'confirmed', 'rescheduled', 'cancelled', 'completed', 'no_show'];

    protected $fillable = ['workspace_id', 'contact_id', 'service_id', 'staff_id', 'lead_id', 'starts_at', 'ends_at', 'status', 'channel',
        'manage_token', 'external_calendar_event_id', 'reminders', 'notes'];

    protected $hidden = ['manage_token', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminders' => 'array', 'notes' => 'encrypted'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
