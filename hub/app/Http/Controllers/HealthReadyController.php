<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Analytics\Ch;
use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /health/ready (docs/despliegue.md §4): ¿esta versión está lista para atender? MariaDB,
 * ClickHouse y las dos instancias de Redis (colas y streams; caché). Devuelve la versión
 * instalada para que el actualizador no confunda la respuesta de un proceso viejo con la
 * de la versión nueva. Sin datos de clientes.
 */
final class HealthReadyController
{
    public function __invoke(Ch $ch): JsonResponse
    {
        $checks = [
            'mariadb' => self::check(fn () => DB::select('SELECT 1') !== []),
            'clickhouse' => self::check(fn () => $ch->ping()),
            'redis_queue' => self::check(fn () => (bool) Redis::connection('ingest')->command('ping')),
            'redis_cache' => self::check(fn () => (bool) Redis::connection('cache')->command('ping')),
        ];
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'version' => AppVersion::current(),
            'checks' => $checks,
        ], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private static function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
