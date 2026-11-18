<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Workspace;

/** A quién le escribe la facturación: el correo de cobro de la cuenta y los dueños. */
final class BillingRecipients
{
    /** @return list<string> */
    public static function for(Account $account, ?Workspace $workspace = null): array
    {
        $emails = [(string) $account->billing_email];
        $workspaces = $workspace !== null ? [$workspace] : Workspace::query()->where('account_id', $account->id)->get()->all();
        foreach ($workspaces as $ws) {
            foreach ($ws->users()->wherePivot('role', WorkspaceRole::Owner->value)->pluck('email') as $email) {
                $emails[] = (string) $email;
            }
        }

        return array_slice(array_values(array_unique(array_filter($emails, fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false))), 0, 10);
    }
}
