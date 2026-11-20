<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use Illuminate\Support\Facades\DB;

/**
 * Las cuentas propias de quien administra la plataforma (S3): las que tiene en `account_user` (su agencia) o
 * donde es dueño de un espacio de trabajo. Sin plan, una cuenta queda en el plan gratuito por defecto; estas
 * reciben el plan `plataforma` (todo, sin topes) para que el superadmin no pierda nada al quitar «sin plan = todo».
 * Solo se asigna a cuentas sin plan: un plan elegido a mano nunca se pisa.
 */
final class PlatformAccounts
{
    /**
     * Cuentas sin plan de un superadmin (o de todos, sin $userId).
     *
     * @return list<string>
     */
    public static function withoutPlan(?string $userId = null): array
    {
        $viaAccount = DB::table('account_user')->join('users', 'users.id', '=', 'account_user.user_id')
            ->where('users.is_platform_admin', true)
            ->when($userId !== null, fn ($q) => $q->where('users.id', $userId))
            ->pluck('account_user.account_id');
        $viaWorkspace = DB::table('workspace_user')->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->where('users.is_platform_admin', true)->where('workspace_user.role', 'owner')
            ->when($userId !== null, fn ($q) => $q->where('users.id', $userId))
            ->pluck('workspaces.account_id');
        $ids = $viaAccount->merge($viaWorkspace)->map(fn ($id) => (string) $id)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $without = DB::table('accounts')->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', ''))->pluck('id')->all();

        return array_values(array_map(fn ($id): string => (string) $id, $without));
    }

    /**
     * Asigna el plan de la plataforma a esas cuentas. Devuelve las cuentas que cambiaron.
     *
     * @return list<string>
     */
    public static function grant(?string $userId = null): array
    {
        $plan = PlanCatalog::platformPlanId();
        if (! PlanCatalog::ensure($plan)) {
            return [];
        }
        $ids = self::withoutPlan($userId);
        if ($ids !== []) {
            DB::table('accounts')->whereIn('id', $ids)->update(['plan_id' => $plan, 'updated_at' => now()]);
        }

        return $ids;
    }
}
