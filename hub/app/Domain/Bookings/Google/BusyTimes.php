<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Google;

use App\Domain\Bookings\Models\BookingStaff;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Lo ocupado en Google Calendar de una persona, en caché 5 minutos; si Google falla, no bloquea nada. */
final class BusyTimes
{
    public function __construct(private readonly GoogleCalendar $google) {}

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    public function forStaff(BookingStaff $staff, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! GoogleCalendar::configured() || ! $staff->calendarConnected()) {
            return [];
        }
        $key = RedisKey::for($staff->workspace_id, 'busy:'.$staff->id.':'.$from->getTimestamp().':'.$to->getTimestamp());
        try {
            $rows = Cache::remember($key, 300, fn () => array_map(fn (array $b) => [$b[0]->toIso8601String(), $b[1]->toIso8601String()],
                $this->google->busy($staff, $from, $to)));
        } catch (Throwable $e) {
            Log::warning('igr.bookings.busy_failed', ['staff' => $staff->id, 'error' => class_basename($e)]);

            return [];
        }

        return array_map(fn (array $b) => [CarbonImmutable::parse($b[0]), CarbonImmutable::parse($b[1])], (array) $rows);
    }
}
