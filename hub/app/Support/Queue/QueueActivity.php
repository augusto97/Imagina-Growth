<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cuándo terminó el último trabajo de cola de toda la instalación (una señal de operación: si lleva mucho sin moverse,
 * no salen mensajes ni avisos). Lo anota el oyente de `JobProcessed`, como mucho una vez cada `EVERY` segundos por
 * proceso para no escribir en Redis con cada trabajo. Sin caché, no hay dato (null), nunca un error.
 */
final class QueueActivity
{
    public const KEY = 'igr:queue:last_processed_at';

    public const EVERY = 30;

    private static int $lastWrite = 0;

    public static function record(): void
    {
        $now = time();
        if ($now - self::$lastWrite < self::EVERY) {
            return;
        }
        self::$lastWrite = $now;
        try {
            Cache::put(self::KEY, $now, 86400 * 7);
        } catch (Throwable) {
            // Sin caché no hay señal; el trabajo ya terminó bien.
        }
    }

    public static function lastProcessedAt(): ?CarbonImmutable
    {
        try {
            $at = Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($at) ? CarbonImmutable::createFromTimestamp((int) $at) : null;
    }

    /** Para las pruebas: la próxima anotación escribe enseguida. */
    public static function reset(): void
    {
        self::$lastWrite = 0;
    }
}
