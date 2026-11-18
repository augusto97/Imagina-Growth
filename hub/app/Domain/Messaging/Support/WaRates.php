<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Enums\MessageKind;
use Illuminate\Support\Facades\DB;

/**
 * Tarifa de Meta por mensaje entregado según país y categoría (tabla `wa_rates`,
 * se actualiza cada trimestre; la carga M10). Sirve para estimar, no para cobrar:
 * cada cliente le paga a Meta desde su cuenta (cumplimiento.md §5.1).
 */
final class WaRates
{
    public static function category(MessageKind $kind): string
    {
        return match ($kind) {
            MessageKind::Marketing => 'marketing',
            MessageKind::Utility, MessageKind::Transactional => 'utility',
            MessageKind::Authentication => 'authentication',
            MessageKind::Service => 'service',
        };
    }

    public static function rate(string $country, MessageKind $kind): ?float
    {
        $rate = DB::table('wa_rates')->where('country', strtoupper($country))->where('category', self::category($kind))
            ->where('effective_from', '<=', now()->toDateString())->orderByDesc('effective_from')->value('rate_usd');

        return $rate === null ? null : (float) $rate;
    }
}
