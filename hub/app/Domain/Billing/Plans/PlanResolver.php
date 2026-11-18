<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Domain\Billing\Models\Plan;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Plan, módulos y límites de un espacio de trabajo (M21). El plan es de la cuenta
 * (`accounts.plan_id`) y cada espacio de trabajo de la cuenta recibe su volumen.
 * El equipo de la plataforma puede activar o apagar un módulo para un espacio de
 * trabajo (`workspace_feature_overrides`). Sin plan (prueba o cuenta anterior a los
 * planes) todo está permitido.
 *
 * Los módulos se resuelven con Laravel Pennant (`module.<clave>`, alcance: el
 * espacio de trabajo); al cambiar el plan o un ajuste se olvidan los valores
 * guardados para que Pennant los vuelva a resolver.
 */
final class PlanResolver
{
    /** @var array<string, Plan|null> */
    private array $plans = [];

    public static function feature(string $module): string
    {
        return 'module.'.$module;
    }

    public function account(Workspace $workspace): ?Account
    {
        return Account::query()->find($workspace->account_id);
    }

    public function planFor(Workspace $workspace): ?Plan
    {
        $accountId = (string) $workspace->account_id;
        if (! array_key_exists($accountId, $this->plans)) {
            $planId = DB::table('accounts')->where('id', $accountId)->value('plan_id');
            $this->plans[$accountId] = is_string($planId) && $planId !== '' ? Plan::query()->find($planId) : null;
        }

        return $this->plans[$accountId];
    }

    public function override(Workspace $workspace, string $module): ?bool
    {
        $value = DB::table('workspace_feature_overrides')->where('workspace_id', $workspace->id)->where('feature', $module)->value('enabled');

        return $value === null ? null : (bool) $value;
    }

    public function moduleAllowed(Workspace $workspace, string $module): bool
    {
        $override = $this->override($workspace, $module);
        if ($override !== null) {
            return $override;
        }
        $plan = $this->planFor($workspace);

        return $plan === null || in_array($module, (array) $plan->modules, true);
    }

    public function limit(Workspace $workspace, string $key): ?int
    {
        $plan = $this->planFor($workspace);
        if ($plan === null) {
            return null;
        }
        if ($key === $plan->metric) {
            return $plan->included;
        }
        if (in_array($key, ['orders', 'contacts'], true)) {
            return null;
        }
        $value = ((array) ($plan->limits ?? []))[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** Olvida los módulos guardados por Pennant y el plan en memoria (cambio de plan o de ajuste). */
    public function refresh(Workspace $workspace): void
    {
        unset($this->plans[(string) $workspace->account_id]);
        Feature::for($workspace)->forget(array_map(fn (string $m) => self::feature($m), PlanCatalog::modules()));
    }

    public function refreshAccount(Account $account): void
    {
        foreach (Workspace::query()->where('account_id', $account->id)->get() as $workspace) {
            $this->refresh($workspace);
        }
    }
}
