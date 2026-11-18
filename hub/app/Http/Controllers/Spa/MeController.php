<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Controllers\Spa\Concerns\Presenters;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/me: la persona, su 2FA y sus espacios de trabajo con el rol efectivo. */
final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $direct = $user->workspaces()->get();
        $accountIds = $user->accounts()->pluck('accounts.id');
        $viaAgency = Workspace::query()->whereIn('account_id', $accountIds)->whereNotIn('id', $direct->modelKeys())->get();

        $workspaces = $direct->concat($viaAgency)
            ->map(function (Workspace $ws) use ($user): array {
                $role = ResolveWorkspace::roleFor($user, $ws) ?? WorkspaceRole::Viewer;

                return Presenters::workspace($ws, $role);
            })
            ->sortBy('name')
            ->values();

        return response()->json([
            'user' => [
                'id' => $user->publicId(),
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'two_factor_pending' => ($user->getAttributes()['two_factor_secret'] ?? null) !== null && ! $user->hasTwoFactorEnabled(),
                'is_platform_admin' => $user->is_platform_admin,
            ],
            'workspaces' => $workspaces,
            'require_two_factor' => (bool) config('igr.require_two_factor'),
        ]);
    }
}
