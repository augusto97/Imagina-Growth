<?php

declare(strict_types=1);

namespace App\Domain\Agency\Http\Concerns;

use App\Domain\Agency\Support\AgencyAccess;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;

/**
 * El panel de agencia cruza espacios de trabajo, pero solo los de las cuentas de
 * agencia de la persona (`account_user`). Todo ID de cliente que llega se
 * comprueba contra esas cuentas; si no es de ellas, 404 (no se confirma que exista).
 */
trait ResolvesAgency
{
    protected function agencyUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! AgencyAccess::canView($user, app(CurrentWorkspace::class)->get())) {
            abort(403, __('Solo las personas de la agencia ven este panel.'));
        }

        return $user;
    }

    /** @return list<string> */
    protected function agencyAccountIds(User $user): array
    {
        return AgencyAccess::accountIds($user);
    }

    protected function clientWorkspace(User $user, string $publicId): Workspace
    {
        $workspace = Workspace::findByPublicId($publicId);
        if ($workspace === null || ! in_array((string) $workspace->account_id, $this->agencyAccountIds($user), true)) {
            abort(404);
        }

        return $workspace;
    }

    protected function ownedAccountId(User $user, string $accountPublicId): string
    {
        $id = str_starts_with($accountPublicId, 'acc_') ? substr($accountPublicId, 4) : $accountPublicId;
        if (! in_array($id, $this->agencyAccountIds($user), true)) {
            abort(404);
        }
        if (! AgencyAccess::isOwner($user, $id)) {
            abort(403, __('Solo el dueño de la agencia puede cambiar esto.'));
        }

        return $id;
    }
}
