<?php

declare(strict_types=1);

namespace App\Domain\Measurement\AdSpend;

use App\Domain\Measurement\Models\AdDestination;
use Carbon\CarbonImmutable;

interface SpendImporter
{
    public function supports(AdDestination $destination): bool;

    /** @return list<SpendRow> */
    public function fetch(AdDestination $destination, CarbonImmutable $from, CarbonImmutable $to): array;
}
