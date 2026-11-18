<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Workspaces\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * `Entitlements` con planes (M21). Además de los módulos del plan responde dos
 * permisos de estado de la cuenta, que nunca tocan la ingesta ni los datos:
 * - `marketing_sends`: enviar mensajes de marketing (campañas y flujos).
 * - `imports`: importar contactos o un historial nuevo.
 * Ambos se apagan solo con la cuenta suspendida (pasado el periodo de gracia).
 */
final class PlanEntitlements implements Entitlements
{
    public const BLOCKED_WHEN_SUSPENDED = ['marketing_sends', 'imports'];

    public function __construct(private readonly PlanResolver $resolver) {}

    public function allows(Workspace $workspace, string $module): bool
    {
        if (in_array($module, self::BLOCKED_WHEN_SUSPENDED, true)) {
            return BillingStatus::of((string) $workspace->account_id) !== BillingStatus::SUSPENDED;
        }
        if (! in_array($module, PlanCatalog::modules(), true)) {
            return true;
        }

        return Feature::for($workspace)->active(PlanResolver::feature($module));
    }

    public function limit(Workspace $workspace, string $key): ?int
    {
        return $this->resolver->limit($workspace, $key);
    }

    public function planFor(Workspace $workspace): ?string
    {
        return $this->resolver->planFor($workspace)?->id;
    }
}
