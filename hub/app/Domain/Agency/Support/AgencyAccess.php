<?php

declare(strict_types=1);

namespace App\Domain\Agency\Support;

use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quién ve la capa de agencia (M22). No sale del rol del espacio de trabajo: sale
 * de `account_user` (agency_owner o agency_member) en una cuenta de tipo agencia.
 * Así el dueño de un cliente de la agencia nunca ve a los otros clientes.
 *
 * En los espacios de trabajo de sus clientes, `ResolveWorkspace` ya da a
 * agency_owner el rol owner y a agency_member el rol admin (Fase 0); cada entrada
 * de alguien que no es miembro directo deja una sesión de soporte auditada
 * (`SupportSessions`).
 */
final class AgencyAccess
{
    /** @return Collection<int, Account> cuentas de agencia de la persona */
    public static function accounts(User $user): Collection
    {
        /** @var Collection<int, Account> $accounts */
        $accounts = $user->accounts()->where('accounts.type', AccountType::Agency->value)->get();

        return $accounts;
    }

    /** @return list<string> */
    public static function accountIds(User $user): array
    {
        // Una consulta por petición (el panel la pide por cada espacio de trabajo de /me).
        $key = 'igr.agency_accounts.'.$user->id;
        $attributes = request()->route() !== null ? request()->attributes : null; // solo dentro de una petición
        $cached = $attributes?->get($key);
        if (is_array($cached)) {
            /** @var list<string> $cached */
            return $cached;
        }
        $ids = array_values(array_map('strval', self::accounts($user)->modelKeys()));
        $attributes?->set($key, $ids);

        return $ids;
    }

    /** ¿Ve la agencia dueña de este espacio de trabajo? */
    public static function canView(User $user, Workspace $workspace): bool
    {
        return in_array((string) $workspace->account_id, self::accountIds($user), true);
    }

    public static function role(User $user, string $accountId): ?string
    {
        $pivot = $user->accounts()->whereKey($accountId)->first()?->getRelationValue('pivot');
        $role = $pivot?->getAttribute('role');

        return is_string($role) ? $role : null;
    }

    public static function isOwner(User $user, string $accountId): bool
    {
        return self::role($user, $accountId) === 'agency_owner';
    }

    /** Entra por la agencia y no es miembro directo del espacio de trabajo. */
    public static function viaAgency(User $user, Workspace $workspace): bool
    {
        return ! $workspace->users()->whereKey($user->id)->exists() && self::canView($user, $workspace);
    }

    /**
     * Permisos extra para la SPA: `viewAgency` cuando la persona es de la agencia
     * dueña del espacio de trabajo.
     *
     * @return list<string>
     */
    public static function extraAbilities(?User $user, Workspace $workspace): array
    {
        return $user !== null && self::canView($user, $workspace) ? ['viewAgency'] : [];
    }
}
