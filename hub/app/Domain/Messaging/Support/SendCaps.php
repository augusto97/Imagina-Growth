<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use App\Domain\Messaging\Models\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Topes de envío de una cuenta, aparte de la frecuencia por contacto:
 * - Calentamiento de un dominio de correo nuevo: tope diario creciente
 *   (config igr-messaging.warmup) desde `warmup_started_at`.
 * - WhatsApp no oficial (Zender, Imagina Whats): topes fijos por hora y por día de la plataforma (cumplimiento.md §5.2).
 * `reserve` devuelve null si hay cupo (y lo toma) o cuándo se puede volver a intentar.
 */
final class SendCaps
{
    public static function reserve(ChannelAccount $account): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $taken = [];
        foreach (self::caps($account, $now) as [$key, $limit, $until]) {
            $count = (int) Redis::connection()->incr($key);
            if ($count === 1) {
                Redis::connection()->expire($key, 2 * 86400);
            }
            $taken[] = $key;
            if ($count > $limit) {
                foreach ($taken as $k) {
                    Redis::connection()->decr($k);
                }

                return $until;
            }
        }

        return null;
    }

    /** Devuelve el cupo tomado con reserve (el envío no salió). */
    public static function release(ChannelAccount $account): void
    {
        foreach (self::caps($account, CarbonImmutable::now()) as [$key]) {
            if ((int) Redis::connection()->get($key) > 0) {
                Redis::connection()->decr($key);
            }
        }
    }

    /**
     * Uso de hoy frente al tope del calentamiento (para el panel).
     *
     * @return array{day: int, limit: int|null, sent_today: int}|null
     */
    public static function warmup(ChannelAccount $account): ?array
    {
        if ($account->warmup_started_at === null || $account->channel->value !== 'email') {
            return null;
        }
        $day = (int) CarbonImmutable::instance($account->warmup_started_at)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()) + 1;
        $schedule = (array) config('igr-messaging.warmup');

        return [
            'day' => $day,
            'limit' => isset($schedule[$day - 1]) ? (int) $schedule[$day - 1] : null,
            'sent_today' => (int) Redis::connection()->get(self::key($account, 'd', CarbonImmutable::now()->format('Ymd'))),
        ];
    }

    /** @return list<array{0: string, 1: int, 2: CarbonImmutable}> */
    private static function caps(ChannelAccount $account, CarbonImmutable $now): array
    {
        $caps = [];
        if (($fixed = $account->unofficialCaps()) !== null) {
            $caps[] = [self::key($account, 'h', $now->format('YmdH')), $fixed['hourly'], $now->addHour()->startOfHour()];
            $caps[] = [self::key($account, 'd', $now->format('Ymd')), $fixed['daily'], $now->addDay()->startOfDay()];
        }
        $warmup = self::warmup($account);
        if ($warmup !== null && $warmup['limit'] !== null) {
            $caps[] = [self::key($account, 'd', $now->format('Ymd')), $warmup['limit'], $now->addDay()->startOfDay()];
        }

        return $caps;
    }

    private static function key(ChannelAccount $account, string $period, string $stamp): string
    {
        return 'ws:'.$account->workspace_id.':cap:'.$account->id.':'.$period.':'.$stamp;
    }
}
