<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Domain\Billing\Usage\BillingStatus;
use App\Domain\Billing\Usage\EvaluateBillingStatus;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Asigna (o quita) el plan de una cuenta: refresca los módulos de sus espacios, sale de la prueba al tener plan,
 * reevalúa la gracia por el nuevo volumen y deja auditoría. La usan la plataforma y los pedidos de cambio de plan.
 */
final class AssignPlan
{
    public function __construct(
        private readonly PlanResolver $plans,
        private readonly EvaluateBillingStatus $status,
        private readonly RecordAuditLog $audit,
    ) {}

    public function handle(Account $account, ?string $planId, ?User $actor): void
    {
        $from = $account->plan_id;
        $account->forceFill(['plan_id' => $planId])->save();
        if (BillingStatus::of($account->id) === BillingStatus::TRIAL && $planId !== null) {
            DB::table('accounts')->where('id', $account->id)->update(['billing_status' => BillingStatus::ACTIVE, 'updated_at' => now()]);
        }
        $this->plans->refreshAccount($account);
        $this->status->handle($account->refresh());
        $this->audit->handle('platform.plan_assigned', $account, ['from' => $from, 'to' => $planId], null, $account->id, $actor?->id);
    }
}
