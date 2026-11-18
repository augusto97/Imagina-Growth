<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Persona que atiende citas (M18). `calendar` guarda cifrada la conexión con
 * Google Calendar: `{access_token, refresh_token, expires_at, calendar_id, email}`.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $email
 * @property string $timezone
 * @property array<string, mixed>|null $calendar
 * @property bool $active
 */
class BookingStaff extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'sf';

    protected $table = 'booking_staff';

    protected $fillable = ['workspace_id', 'user_id', 'name', 'email', 'timezone', 'calendar', 'active'];

    protected $hidden = ['calendar', 'email'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'calendar' => EncryptedJson::class, 'active' => 'boolean'];
    }

    public function calendarConnected(): bool
    {
        return is_array($this->calendar) && ($this->calendar['refresh_token'] ?? '') !== '';
    }
}
