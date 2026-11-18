<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toma el espacio de trabajo de /api/w/{workspace}/..., verifica la membresía
 * (directa o como miembro de la agencia dueña de la cuenta) y exige 2FA a
 * owner y admin (modulos.md M01, hub/CLAUDE.md §4).
 */
final class ResolveWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $workspace = Workspace::findByPublicId((string) $request->route('workspace'));
        if ($user === null || $workspace === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $role = self::roleFor($user, $workspace);
        if ($role === null) {
            // 404 y no 403: no se confirma que el espacio de trabajo existe.
            return response()->json(['error' => 'not_found'], 404);
        }
        if (config('igr.require_two_factor') && $role->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'error' => 'two_factor_required',
                'message' => __('Activa la verificación en dos pasos para administrar este espacio de trabajo.'),
            ], 403);
        }

        $this->current->set($workspace, $role);
        $request->route()?->forgetParameter('workspace');

        return $next($request);
    }

    public static function roleFor(User $user, Workspace $workspace): ?WorkspaceRole
    {
        $direct = $workspace->users()->whereKey($user->id)->first()?->pivot?->getAttribute('role');
        if (is_string($direct)) {
            return WorkspaceRole::from($direct);
        }
        $accountRole = $user->accounts()->whereKey($workspace->account_id)->first()?->pivot?->getAttribute('role');

        return is_string($accountRole) ? WorkspaceRole::fromAccountRole(AccountRole::from($accountRole)) : null;
    }
}
