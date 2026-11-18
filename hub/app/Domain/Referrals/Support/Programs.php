<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Referrals\Models\ReferralProgram;
use App\Support\Tenancy\CurrentWorkspace;

/** El programa de referidos del espacio de trabajo actual. */
final class Programs
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function settings(): ?ProgramSettings
    {
        $program = ReferralProgram::query()->first();

        return $program === null ? null : new ProgramSettings($program, $this->current->get());
    }

    /** El programa solo si está activo. */
    public function active(): ?ProgramSettings
    {
        $settings = $this->settings();

        return $settings !== null && $settings->program->isActive() ? $settings : null;
    }
}
