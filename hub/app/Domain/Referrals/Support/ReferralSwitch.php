<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Support;

use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\ReferralProgram;
use Illuminate\Support\Facades\Cache;

/**
 * ¿El espacio de trabajo usa referidos o afiliados? La ingesta lo pregunta por cada
 * pedido, así que va en caché 5 minutos; guardar el programa o un afiliado la borra.
 */
final class ReferralSwitch
{
    /** @return array{referrals: bool, affiliates: bool} */
    public static function state(string $workspaceId): array
    {
        /** @var array{referrals: bool, affiliates: bool} */
        return Cache::remember(self::key($workspaceId), 300, fn (): array => [
            'referrals' => ReferralProgram::withoutWorkspaceScope()->where('workspace_id', $workspaceId)->where('status', 'active')->exists(),
            'affiliates' => Affiliate::withoutWorkspaceScope()->where('workspace_id', $workspaceId)->where('status', 'active')->exists(),
        ]);
    }

    public static function forget(string $workspaceId): void
    {
        Cache::forget(self::key($workspaceId));
    }

    private static function key(string $workspaceId): string
    {
        return 'igr:referrals:switch:'.$workspaceId;
    }
}
