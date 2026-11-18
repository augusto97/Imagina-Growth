<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Workspaces\Models\Workspace;

final class UnlimitedEntitlements implements Entitlements
{
    public function allows(Workspace $workspace, string $module): bool
    {
        return true;
    }

    public function limit(Workspace $workspace, string $key): ?int
    {
        return null;
    }

    public function planFor(Workspace $workspace): ?string
    {
        return null;
    }
}
