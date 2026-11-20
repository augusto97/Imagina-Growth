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
 * trabajo (`workspace_feature_overrides`).
 *
 * Sin plan asignado no es «todo» (docs/auditoria-2026-11.md S3): aplica el plan por defecto
 * (`igr-plans.default_plan`, Diagnóstico: analítica, segmentos, RFM y hallazgos, IA mínima y sin
 * correo por la plataforma). Solo `default_plan = none` deja todo permitido (desarrollo y pruebas).
 * Las cuentas propias del superadmin tienen asignado el plan `plataforma` (todo, sin topes): lo
 * asignan la migración `2026_11_25_300100`, `CreateWorkspace` cuando un superadmin crea su propia
 * cuenta e `igr:admin:create`.
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

    /** El plan que rige: el asignado a la cuenta o, sin plan (o con uno que ya no existe), el plan por defecto. */
    public function planFor(Workspace $workspace): ?Plan
    {
        $accountId = (string) $workspace->account_id;
        if (! array_key_exists($accountId, $this->plans)) {
            $this->plans[$accountId] = $this->assignedPlan($accountId) ?? self::defaultPlan();
        }

        return $this->plans[$accountId];
    }

    /** `assigned` (la cuenta tiene plan), `default` (rige el plan por defecto) o `unrestricted` (`default_plan = none`). */
    public function source(Workspace $workspace): string
    {
        if ($this->assignedPlan((string) $workspace->account_id) !== null) {
            return 'assigned';
        }

        return PlanCatalog::defaultPlanId() === null ? 'unrestricted' : 'default';
    }

    /** El plan por defecto de una cuenta sin plan, o null con `default_plan = none`. */
    public static function defaultPlan(): ?Plan
    {
        $id = PlanCatalog::defaultPlanId();

        return $id === null ? null : PlanCatalog::findOrDefinition($id);
    }

    private function assignedPlan(string $accountId): ?Plan
    {
        $planId = DB::table('accounts')->where('id', $accountId)->value('plan_id');

        return is_string($planId) && $planId !== '' ? Plan::query()->find($planId) : null;
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
            // 0 incluidos = sin tope de volumen (el plan gratuito y el de la plataforma).
            return $plan->included > 0 ? $plan->included : null;
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

    /**
     * Olvida los módulos guardados de todos los espacios: al editar el plan por defecto o el de la plataforma, o
     * al cambiar `IGR_DEFAULT_PLAN` (`igr:plans:sync --refresh`).
     */
    public function refreshAll(): void
    {
        $this->plans = [];
        Feature::purge(array_map(fn (string $m) => self::feature($m), PlanCatalog::modules()));
    }
}
