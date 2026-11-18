<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Models\Workspace;

final class UpdateWorkspaceSettings
{
    public function __construct(private RecordAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  campos ya validados
     */
    public function handle(Workspace $workspace, array $attributes): Workspace
    {
        $workspace->fill($attributes);
        $changes = $workspace->getDirty();
        if ($changes !== []) {
            $workspace->save();
            $this->audit->handle('workspace.updated', $workspace, $changes, $workspace->id, $workspace->account_id);
        }

        return $workspace;
    }
}
