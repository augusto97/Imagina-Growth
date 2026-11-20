<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Billing\Plans\AssignPlan;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * La plataforma da de alta un cliente: la empresa (cuenta directa o agencia), su primer espacio de trabajo, el plan
 * y la invitación al dueño. Quien la crea no queda dentro del espacio del cliente; el dueño entra al aceptar la
 * invitación (si es una agencia, también queda como dueño de la cuenta).
 */
final class CreateClientAccount
{
    public function __construct(
        private readonly CreateWorkspace $workspaces,
        private readonly InviteToWorkspace $invite,
        private readonly AssignPlan $assignPlan,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, type: string, country: string, billing_email?: string|null, plan_id?: string|null, owner_email: string}  $account
     * @return array{account: Account, workspace: Workspace, invitation: Invitation}
     */
    public function handle(User $actor, array $account, WorkspaceData $workspace): array
    {
        $type = AccountType::from($account['type']);
        [$model, $space] = DB::transaction(function () use ($account, $type, $workspace, $actor): array {
            $model = Account::query()->create([
                'type' => $type,
                'name' => $account['name'],
                'country' => $account['country'],
                'billing_email' => $account['billing_email'] ?? $account['owner_email'],
                'status' => 'active',
            ]);
            $space = $this->workspaces->createInAccount($model, $workspace, null, $actor);
            $this->audit->handle('platform.client_created', $model, ['name' => $model->name, 'type' => $type->value], $space->id, $model->id, $actor->id);

            return [$model, $space];
        });

        if (! empty($account['plan_id'])) {
            $this->assignPlan->handle($model, $account['plan_id'], $actor);
        }
        $invitation = $this->invite->handle(
            $space,
            $actor,
            $account['owner_email'],
            WorkspaceRole::Owner,
            $type === AccountType::Agency ? AccountRole::AgencyOwner : null,
        );

        return ['account' => $model->refresh(), 'workspace' => $space, 'invitation' => $invitation];
    }
}
