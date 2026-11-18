<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Console\WorkspaceOption;
use Illuminate\Database\Eloquent\Collection;

trait SelectsWorkspaces
{
    /** @return Collection<int, Workspace> */
    protected function workspaces(): Collection
    {
        $value = $this->option('workspace');

        return WorkspaceOption::resolve(is_array($value) || is_string($value) ? $value : null);
    }
}
