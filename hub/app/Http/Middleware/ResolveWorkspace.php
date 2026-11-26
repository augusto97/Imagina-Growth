<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Agency\Support\SupportSessions;
use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\WorkspaceLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toma el espacio de trabajo de /api/w/{workspace}/..., verifica la membresía
 * (directa o como miembro de la agencia dueña de la cuenta) y exige 2FA a
 * owner y admin (modulos.md M01, hub/CLAUDE.md §4). Quien entra por la agencia
 * sin ser miembro directo deja una sesión de soporte auditada (M22).
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

        [$role, $direct] = self::membership($user, $workspace);
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
        if (! $direct) {
            app(SupportSessions::class)->onAgencyAccess($user, $workspace, $request->ip());
        }

        // Los mensajes y errores de la petición salen en el idioma del espacio de trabajo.
        return WorkspaceLocale::run($workspace, fn (): Response => $next($request));
    }

    public static function roleFor(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return self::membership($user, $workspace)[0];
    }

    /** @return array{0: WorkspaceRole|null, 1: bool} el rol y si es miembro directo */
    public static function membership(User $user, Workspace $workspace): array
    {
        $direct = $workspace->users()->whereKey($user->id)->first()?->pivot?->getAttribute('role');
        if (is_string($direct)) {
            return [WorkspaceRole::from($direct), true];
        }
        $accountRole = $user->accounts()->whereKey($workspace->account_id)->first()?->pivot?->getAttribute('role');

        return [self::roleFrom(null, $accountRole), false];
    }

    /**
     * El rol efectivo con los roles ya leídos: el de miembro directo gana; si no, el de la cuenta (agencia). Lo usa
     * `/api/me` para resolver todos los espacios de trabajo con dos consultas en vez de dos por espacio.
     */
    public static function roleFrom(mixed $directRole, mixed $accountRole): ?WorkspaceRole
    {
        if (is_string($directRole)) {
            return WorkspaceRole::from($directRole);
        }

        return is_string($accountRole) ? WorkspaceRole::fromAccountRole(AccountRole::from($accountRole)) : null;
    }
}
