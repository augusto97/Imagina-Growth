<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Campaigns\Support\WaRateCard;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tarifa de Meta por mensaje entregado según país y categoría (tabla `wa_rates`, WaRateCard: mercado del país y
 * «otros» de respaldo). Sirve para estimar, no para cobrar: cada cliente le paga a Meta desde su cuenta
 * (cumplimiento.md §5.1).
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

    public static function rate(string $country, MessageKind $kind, ?CarbonImmutable $on = null): ?float
    {
        return WaRateCard::rate($country, self::category($kind), $on);
    }

    /**
     * Costo estimado de un mensaje al enviarlo. Los de servicio (respuestas dentro de la ventana de 24 h) son gratis
     * hasta el mensaje 1.000 de cada número en el mes (desde el 1 oct 2026; antes, siempre): se lleva la cuenta por
     * número y mes en la caché. Si la caché no responde, se estima con la tarifa (mejor de más que de menos).
     */
    public static function estimate(ChannelAccount $account, string $country, MessageKind $kind, ?CarbonImmutable $now = null): ?float
    {
        $now ??= CarbonImmutable::now('UTC');
        $rate = self::rate($country, $kind, $now);
        if ($rate === null || $kind !== MessageKind::Service || $rate <= 0.0) {
            return $rate;
        }
        if ($now->toDateString() < WaRateCard::FREE_SERVICE_FROM) {
            return 0.0;
        }
        try {
            $key = 'ws:'.$account->workspace_id.':wa_service:'.$account->id.':'.$now->format('Ym');
            Cache::add($key, 0, $now->endOfMonth()->addDays(2));
            $n = (int) Cache::increment($key);
        } catch (Throwable) {
            return $rate;
        }

        return $n <= WaRateCard::FREE_SERVICE_PER_NUMBER ? 0.0 : $rate;
    }
}
