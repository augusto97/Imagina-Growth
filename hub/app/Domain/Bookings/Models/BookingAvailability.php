<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Franja semanal de atención (M18). Sin `staff_id` es el horario del negocio, que
 * usan las personas sin horario propio. `weekday` es ISO (1 = lunes). `exceptions`:
 * `[{date: 'YYYY-MM-DD', closed: true}]` o `[{date, start: 'HH:MM', end: 'HH:MM'}]`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $staff_id
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 * @property list<array<string, mixed>>|null $exceptions
 */
class BookingAvailability extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'ba';

    protected $table = 'booking_availability';

    protected $fillable = ['workspace_id', 'staff_id', 'weekday', 'start_time', 'end_time', 'exceptions'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'exceptions' => 'array'];
    }
}
