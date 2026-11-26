<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Events\WorkspaceVerticalChanged;
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
            if (array_key_exists('vertical', $changes)) {
                WorkspaceVerticalChanged::dispatch($workspace, is_string($changes['vertical']) ? $changes['vertical'] : null);
            }
        }

        return $workspace;
    }
}
