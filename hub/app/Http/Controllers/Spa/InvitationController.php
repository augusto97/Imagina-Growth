<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Actions\AcceptInvitation;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitationController
{
    /** GET /api/invitations/{token}: vista previa pública (sin datos de otros miembros). */
    public function show(string $token): JsonResponse
    {
        $invitation = Invitation::withoutWorkspaceScope()->where('token_hash', hash('sha256', $token))->first();
        if ($invitation === null || ! $invitation->isPending()) {
            return response()->json(['error' => 'invalid'], 404);
        }
        $workspace = Workspace::query()->find($invitation->workspace_id);

        return response()->json([
            'workspace' => $workspace?->name,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'has_account' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    /** POST /api/invitations/{token}/accept */
    public function accept(Request $request, string $token, AcceptInvitation $accept): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $accept->handle($token, $user);

        return response()->json(['workspace_id' => $workspace->publicId()]);
    }
}
