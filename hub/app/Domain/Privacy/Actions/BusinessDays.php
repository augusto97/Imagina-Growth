<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Plazos legales en días hábiles (sin fines de semana ni festivos del país). */
final class BusinessDays
{
    public static function add(CarbonInterface $from, int $days, string $country): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from);
        $added = 0;
        while ($added < $days) {
            $date = $date->addDay();
            if (! $date->isWeekend() && ! Holidays::isHoliday($country, $date)) {
                $added++;
            }
        }

        return $date;
    }
}
