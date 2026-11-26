<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Queries\WorkspaceFeatures;
use App\Http\Controllers\Spa\Concerns\Presenters;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/me: la persona, su 2FA y sus espacios de trabajo con el rol efectivo. */
final class MeController
{
    public function __invoke(Request $request, WorkspaceFeatures $features): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Los roles salen de las dos membresías ya cargadas (directa y por cuenta), no de dos consultas por espacio:
        // una agencia con 40 clientes hacía más de 200 consultas en cada carga del panel.
        $direct = $user->workspaces()->get();
        $accountRoles = $user->accounts()->get()->mapWithKeys(fn ($a) => [(string) $a->getKey() => $a->getRelationValue('pivot')?->getAttribute('role')]);
        $viaAgency = Workspace::query()->whereIn('account_id', $accountRoles->keys())->whereNotIn('id', $direct->modelKeys())->get();

        $all = $direct->concat($viaAgency)->values();
        $computed = $features->forMany($all);
        $billing = Presenters::billingFor(array_values(array_unique($all->map(fn (Workspace $ws) => (string) $ws->account_id)->all())));
        $workspaces = $all
            ->map(function (Workspace $ws) use ($accountRoles, $computed, $billing): array {
                $directRole = $ws->relationLoaded('pivot') ? $ws->getRelationValue('pivot')?->getAttribute('role') : null;
                $role = ResolveWorkspace::roleFrom($directRole, $accountRoles->get((string) $ws->account_id)) ?? WorkspaceRole::Viewer;

                return Presenters::workspace($ws, $role, $computed[$ws->id] ?? null, $billing[(string) $ws->account_id] ?? null);
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
