<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Mail\InvitationMail;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InviteMember
{
    public function __construct(private RecordAuditLog $audit) {}

    public function handle(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role, ?WorkspaceRole $inviterRole): Invitation
    {
        if ($role === WorkspaceRole::Owner && $inviterRole !== WorkspaceRole::Owner) {
            throw ValidationException::withMessages(['role' => __('Solo un dueño puede invitar a otro dueño.')]);
        }
        $email = mb_strtolower(trim($email));
        if ($workspace->users()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('Esa persona ya es miembro del espacio de trabajo.')]);
        }

        Invitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(48);
        $invitation = Invitation::query()->create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($email)->send(new InvitationMail($workspace, $inviter, $token, $role));
        $this->audit->handle('member.invited', $invitation, ['email' => $email, 'role' => $role->value], $workspace->id, $workspace->account_id, $inviter->id);

        return $invitation;
    }
}
