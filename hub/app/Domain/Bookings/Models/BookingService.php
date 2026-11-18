<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Models;

use App\Support\Ids\HasPrefixedUlid;
use App\Support\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Servicio que se puede reservar (M18).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $duration_min
 * @property int $buffer_min
 * @property string|null $price
 * @property string $location onsite | online | phone
 * @property list<string>|null $staff_ids
 * @property int $min_notice_hours
 * @property int $max_days_ahead
 * @property bool $active
 */
class BookingService extends Model
{
    use BelongsToWorkspace, HasPrefixedUlid;

    public const ID_PREFIX = 'bs';

    public const LOCATIONS = ['onsite', 'online', 'phone'];

    protected $fillable = ['workspace_id', 'name', 'slug', 'description', 'duration_min', 'buffer_min', 'price', 'location', 'staff_ids',
        'min_notice_hours', 'max_days_ahead', 'active'];

    protected function casts(): array
    {
        return ['staff_ids' => 'array', 'active' => 'boolean', 'duration_min' => 'integer', 'buffer_min' => 'integer',
            'min_notice_hours' => 'integer', 'max_days_ahead' => 'integer', 'price' => 'decimal:2'];
    }
}
