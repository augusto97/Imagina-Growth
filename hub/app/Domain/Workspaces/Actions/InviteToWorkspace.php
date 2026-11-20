<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Data\IssuedInvitation;
use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Mail\InvitationMail;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invita a alguien a un espacio de trabajo sin depender del espacio actual de la petición (la plataforma y la
 * agencia crean clientes desde otro espacio). Con $accountRole, al aceptar también entra a la cuenta (p. ej. el
 * dueño de una agencia cliente). Reemplaza la invitación pendiente de ese correo en ese espacio.
 */
final class InviteToWorkspace
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function handle(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role, ?AccountRole $accountRole = null): IssuedInvitation
    {
        $email = mb_strtolower(trim($email));
        if ($workspace->users()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('Esa persona ya es miembro del espacio de trabajo.')]);
        }
        Invitation::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->delete();

        $token = Str::random(48);
        $invitation = Invitation::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => $role,
            'account_role' => $accountRole?->value,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(14),
        ]);

        Mail::to($email)->send(new InvitationMail($workspace, $inviter, $token, $role));
        $this->audit->handle('member.invited', $invitation, ['email' => $email, 'role' => $role->value, 'account_role' => $accountRole?->value], $workspace->id, $workspace->account_id, $inviter->id);

        return new IssuedInvitation($invitation, rtrim((string) config('igr.urls.app'), '/').'/invitacion/'.$token);
    }
}
