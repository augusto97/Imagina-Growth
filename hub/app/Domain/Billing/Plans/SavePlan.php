<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Domain\Billing\Models\Plan;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;

/**
 * Crea o edita un plan desde el panel de la plataforma. Al cambiar módulos o límites se refrescan los espacios de
 * las cuentas que lo usan (los módulos viven en Pennant por espacio).
 */
final class SavePlan
{
    public function __construct(private readonly PlanResolver $plans, private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{name: string, metric: string, included: int, modules: list<string>, limits: array<string, int|float|null>, price_usd: float|int|string, price_cop?: float|int|string|null, public: bool}  $data
     */
    public function create(string $id, array $data, ?User $actor): Plan
    {
        $plan = Plan::query()->create(['id' => $id] + $this->attributes($data));
        $this->audit->handle('platform.plan_created', $plan, ['id' => $plan->id], null, null, $actor?->id);

        return $plan;
    }

    /**
     * @param  array{name: string, metric: string, included: int, modules: list<string>, limits: array<string, int|float|null>, price_usd: float|int|string, price_cop?: float|int|string|null, public: bool}  $data
     */
    public function update(Plan $plan, array $data, ?User $actor): Plan
    {
        $plan->fill($this->attributes($data))->save();
        if ($plan->id === PlanCatalog::defaultPlanId() || $plan->id === PlanCatalog::platformPlanId()) {
            // El plan por defecto rige también a las cuentas sin plan: se olvidan los módulos de todos.
            $this->plans->refreshAll();
        } else {
            foreach (Account::query()->where('plan_id', $plan->id)->get() as $account) {
                $this->plans->refreshAccount($account);
            }
        }
        $this->audit->handle('platform.plan_updated', $plan, ['id' => $plan->id, 'changes' => array_keys($plan->getChanges())], null, null, $actor?->id);

        return $plan;
    }

    /**
     * @param  array{name: string, metric: string, included: int, modules: list<string>, limits: array<string, int|float|null>, price_usd: float|int|string, price_cop?: float|int|string|null, public: bool}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'metric' => $data['metric'],
            'included' => (int) $data['included'],
            'modules' => array_values(array_intersect($data['modules'], PlanCatalog::modules())),
            'limits' => array_filter($data['limits'], fn ($v) => $v !== null && $v !== ''),
            'price_usd' => (string) $data['price_usd'],
            'price_cop' => isset($data['price_cop']) && $data['price_cop'] !== '' ? (string) $data['price_cop'] : null,
            'public' => (bool) $data['public'],
        ];
    }
}
