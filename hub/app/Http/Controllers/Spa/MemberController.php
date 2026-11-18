<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Actions\ChangeMemberRole;
use App\Domain\Workspaces\Actions\InviteMember;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Support\Pii\Pii;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MemberController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{workspace}/members */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $ws = $this->current->get();
        $canManage = Gate::allows('ws.manageMembers');

        $members = $ws->users()->orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->publicId(),
            'name' => $u->name,
            'email' => $canManage ? $u->email : Pii::maskEmail($u->email),
            'role' => $u->pivot?->getAttribute('role'),
            'two_factor_enabled' => $u->hasTwoFactorEnabled(),
            'last_login_at' => $u->getAttribute('last_login_at')?->toIso8601String(),
        ]);
        $invitations = $canManage
            ? Invitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->orderByDesc('created_at')->get()
                ->map(fn (Invitation $i) => ['id' => $i->publicId(), 'email' => $i->email, 'role' => $i->role->value, 'expires_at' => $i->expires_at->toIso8601String()])
            : collect();

        return response()->json(['members' => $members, 'invitations' => $invitations]);
    }

    /** POST /api/w/{workspace}/invitations */
    public function invite(Request $request, InviteMember $invite): JsonResponse
    {
        Gate::authorize('ws.manageMembers');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $invitation = $invite->handle($this->current->get(), $user, $data['email'], WorkspaceRole::from($data['role']), $this->current->role());

        return response()->json(['id' => $invitation->publicId(), 'email' => $invitation->email, 'role' => $invitation->role->value, 'expires_at' => $invitation->expires_at->toIso8601String()], 201);
    }

    /** DELETE /api/w/{workspace}/invitations/{invitation} */
    public function revokeInvitation(string $invitation): JsonResponse
    {
        Gate::authorize('ws.manageMembers');
        Invitation::findByPublicId($invitation)?->delete();

        return response()->json(['ok' => true]);
    }

    /** PATCH /api/w/{workspace}/members/{member} */
    public function update(Request $request, string $member, ChangeMemberRole $change): JsonResponse
    {
        Gate::authorize('ws.manageMembers');
        $data = $request->validate(['role' => ['required', Rule::enum(WorkspaceRole::class)]]);
        $user = User::findByPublicId($member) ?? abort(404);
        $change->handle($this->current->get(), $user, WorkspaceRole::from($data['role']), $this->current->role());

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/w/{workspace}/members/{member} */
    public function destroy(string $member, ChangeMemberRole $change): JsonResponse
    {
        Gate::authorize('ws.manageMembers');
        $user = User::findByPublicId($member) ?? abort(404);
        $change->handle($this->current->get(), $user, null, $this->current->role());

        return response()->json(['ok' => true]);
    }
}
