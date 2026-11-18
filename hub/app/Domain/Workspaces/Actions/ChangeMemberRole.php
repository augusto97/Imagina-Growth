<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Validation\ValidationException;

final class ChangeMemberRole
{
    public function __construct(private RecordAuditLog $audit) {}

    /** $role null = quitar del espacio de trabajo. */
    public function handle(Workspace $workspace, User $member, ?WorkspaceRole $role, ?WorkspaceRole $actorRole): void
    {
        $current = $workspace->users()->whereKey($member->id)->first()?->pivot?->getAttribute('role');
        if ($current === null) {
            throw ValidationException::withMessages(['member' => __('Esa persona no es miembro.')]);
        }
        $touchesOwner = $current === WorkspaceRole::Owner->value || $role === WorkspaceRole::Owner;
        if ($touchesOwner && $actorRole !== WorkspaceRole::Owner) {
            throw ValidationException::withMessages(['role' => __('Solo un dueño puede cambiar a un dueño.')]);
        }
        if ($current === WorkspaceRole::Owner->value && $role !== WorkspaceRole::Owner) {
            $owners = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->count();
            if ($owners <= 1) {
                throw ValidationException::withMessages(['role' => __('El espacio de trabajo debe tener al menos un dueño.')]);
            }
        }

        if ($role === null) {
            $workspace->users()->detach($member->id);
            $this->audit->handle('member.removed', $member, ['role' => $current], $workspace->id, $workspace->account_id);

            return;
        }
        $workspace->users()->updateExistingPivot($member->id, ['role' => $role->value]);
        $this->audit->handle('member.role_changed', $member, ['from' => $current, 'to' => $role->value], $workspace->id, $workspace->account_id);
    }
}
