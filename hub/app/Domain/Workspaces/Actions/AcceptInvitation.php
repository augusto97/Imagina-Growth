<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptInvitation
{
    public function __construct(private RecordAuditLog $audit) {}

    public function handle(string $token, User $user): Workspace
    {
        /** @var Invitation|null $invitation */
        $invitation = Invitation::withoutWorkspaceScope()->where('token_hash', hash('sha256', $token))->first();
        if ($invitation === null || ! $invitation->isPending()) {
            throw ValidationException::withMessages(['token' => __('La invitación no existe o ya venció.')]);
        }
        if (mb_strtolower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['token' => __('La invitación es para otro correo.')]);
        }

        $workspace = Workspace::query()->findOrFail($invitation->workspace_id);

        DB::transaction(function () use ($invitation, $user, $workspace): void {
            $workspace->users()->syncWithoutDetaching([$user->id => ['role' => $invitation->role->value]]);
            $invitation->forceFill(['accepted_at' => now()])->save();
        });
        $this->audit->handle('member.joined', $user, ['role' => $invitation->role->value], $workspace->id, $workspace->account_id, $user->id);

        return $workspace;
    }
}
