<?php

declare(strict_types=1);

namespace App\Domain\Services\Support;

use App\Domain\Services\Contracts\LeadIntake;
use App\Domain\Services\Data\LeadIntakeInput;

final class NullLeadIntake implements LeadIntake
{
    public function capture(LeadIntakeInput $input): ?string
    {
        return null;
    }
}
