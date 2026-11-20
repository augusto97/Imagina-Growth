<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Actions;

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea un espacio de trabajo. Sin cuenta, crea una cuenta directa nueva; con
 * cuenta, exige que la persona sea de la agencia o dueña de otro espacio de
 * trabajo de esa cuenta.
 */
final class CreateWorkspace
{
    public function __construct(private RecordAuditLog $audit) {}

    public function handle(User $user, WorkspaceData $data, ?Account $account = null): Workspace
    {
        if ($account !== null && ! $this->canCreateIn($user, $account)) {
            throw new AuthorizationException(__('No puedes crear espacios de trabajo en esta cuenta.'));
        }

        return DB::transaction(function () use ($user, $data, $account): Workspace {
            // La cuenta propia de quien administra la plataforma nace con el plan de la plataforma (S3): sin plan
            // regiría el plan gratuito por defecto.
            $platformPlan = (bool) ($user->getAttributes()['is_platform_admin'] ?? false) && PlanCatalog::ensure(PlanCatalog::platformPlanId())
                ? PlanCatalog::platformPlanId() : null;
            $account ??= Account::query()->create([
                'type' => AccountType::Direct,
                'name' => $data->name,
                'country' => $data->country,
                'billing_email' => $user->email,
                'plan_id' => $platformPlan,
                'status' => 'active',
            ]);

            return $this->createInAccount($account, $data, $user, $user);
        });
    }

    /**
     * Crea el espacio en una cuenta ya resuelta, sin revisar permisos (quien llama ya los revisó). Con $owner, esa
     * persona queda como dueña; sin él (la plataforma crea un cliente), el dueño entra por invitación.
     */
    public function createInAccount(Account $account, WorkspaceData $data, ?User $owner, ?User $actor): Workspace
    {
        return DB::transaction(function () use ($account, $data, $owner, $actor): Workspace {
            $workspace = Workspace::query()->create([
                'account_id' => $account->id,
                'name' => $data->name,
                'slug' => $this->uniqueSlug($account, $data->name),
                'country' => $data->country,
                'timezone' => $data->timezone,
                'currency' => $data->currency,
                'locale' => $data->locale,
                'industry' => $data->industry,
                'settings' => self::defaultSettings($data->country),
                'status' => 'active',
            ]);

            if ($owner !== null) {
                $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
            }

            $this->audit->handle('workspace.created', $workspace, ['name' => $workspace->name], $workspace->id, $account->id, $actor?->id);

            return $workspace->refresh();
        });
    }

    private function canCreateIn(User $user, Account $account): bool
    {
        $accountRole = $user->accounts()->whereKey($account->id)->first()?->pivot?->getAttribute('role');
        if (in_array($accountRole, [AccountRole::AgencyOwner->value, AccountRole::AgencyMember->value], true)) {
            return true;
        }

        return $user->workspaces()
            ->where('workspaces.account_id', $account->id)
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->exists();
    }

    private function uniqueSlug(Account $account, string $name): string
    {
        $base = Str::slug($name) ?: 'espacio';
        $slug = $base;
        $i = 2;
        while (Workspace::query()->where('account_id', $account->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Ajustes por defecto del espacio de trabajo: siempre los más conservadores
     * (cumplimiento.md, regla de diseño).
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(string $country): array
    {
        return [
            'consent_mode' => 'auto', // según el sitio: estricto con banner, informado sin él (cumplimiento.md §3.3)
            'early_capture' => false,
            'attribution' => ['click_days' => 5, 'open_days' => 1, 'count_opens' => false],
            'frequency' => ['marketing_per_day' => 1, 'marketing_per_week' => 3, 'one_channel_per_week' => false],
            'abandoned_cart_minutes' => 45,
            'default_phone_country' => $country,
        ];
    }
}
