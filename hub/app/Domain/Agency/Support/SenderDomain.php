<?php

declare(strict_types=1);

namespace App\Domain\Agency\Support;

use Illuminate\Support\Facades\DB;

/**
 * ¿El dominio de este remitente está verificado en un canal de correo del espacio
 * de trabajo (SES con DKIM listo, o SMTP)? Sin eso, el correo saldría con un
 * remitente que el proveedor no autoriza.
 */
final class SenderDomain
{
    public static function verifiedFor(string $workspaceId, string $email): bool
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return false;
        }
        foreach (DB::table('channel_accounts')->where('workspace_id', $workspaceId)->where('channel', 'email')->where('status', 'active')->get(['identity']) as $row) {
            $identity = strtolower((string) ($row->identity ?? ''));
            if ($identity === $domain || str_ends_with($identity, '@'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
