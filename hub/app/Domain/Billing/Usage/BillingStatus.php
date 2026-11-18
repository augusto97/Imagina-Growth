<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use Illuminate\Support\Facades\DB;

/**
 * Estado de cobro de una cuenta (M21), sin cortes bruscos:
 * `trial` → `active`; pasado el 100 % del volumen del plan o con una factura vencida →
 * `grace` (7 días, con aviso); si al terminar la gracia sigue igual → `suspended`, que
 * solo bloquea el marketing y las importaciones nuevas (`PlanEntitlements`). Al
 * resolverse (mes nuevo por debajo del límite, cambio de plan o pago) vuelve a `active`.
 */
final class BillingStatus
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const GRACE = 'grace';

    public const SUSPENDED = 'suspended';

    public static function of(string $accountId): string
    {
        return (string) (DB::table('accounts')->where('id', $accountId)->value('billing_status') ?: self::ACTIVE);
    }
}
