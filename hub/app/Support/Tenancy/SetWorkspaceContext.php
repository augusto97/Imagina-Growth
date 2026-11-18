<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Workspaces\Models\Workspace;
use Closure;

/** Middleware de job: restaura el espacio de trabajo del job (hub/CLAUDE.md §4). */
final class SetWorkspaceContext
{
    public function handle(WorkspaceAware $job, Closure $next): mixed
    {
        $workspace = Workspace::query()->findOrFail($job->workspaceId());

        return app(CurrentWorkspace::class)->run($workspace, fn () => $next($job));
    }
}
