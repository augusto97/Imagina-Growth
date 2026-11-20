<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;

/**
 * ¿Quién ve el detalle de /health y /health/ready? (S16). En público solo se dice si está bien o no; el detalle
 * (cada dependencia, el atraso de los streams, la versión) sale:
 *
 * - desde la propia máquina: la conexión viene de 127.0.0.1 o ::1 **sin** encabezados de proxy (Caddy siempre agrega
 *   X-Forwarded-For, así que lo que llega de Internet nunca cuenta como local); así lo usa `growth-update`;
 * - o con `IGR_HEALTH_TOKEN` en `X-IGR-Health-Token` o `Authorization: Bearer` (monitores externos).
 */
final class HealthAccess
{
    private const PROXY_HEADERS = ['X-Forwarded-For', 'Forwarded', 'X-Real-Ip', 'X-Forwarded-Host', 'Cf-Connecting-Ip'];

    public static function detailed(Request $request): bool
    {
        $token = (string) config('igr.health.token');
        if ($token !== '') {
            $given = (string) ($request->header('X-IGR-Health-Token') ?: $request->bearerToken() ?: '');
            if ($given !== '' && hash_equals($token, $given)) {
                return true;
            }
        }

        return self::fromThisMachine($request);
    }

    public static function fromThisMachine(Request $request): bool
    {
        // La IP de la conexión, no $request->ip(): esa sigue a X-Forwarded-For y la puede escribir cualquiera.
        $peer = $request->server->get('REMOTE_ADDR');
        if (! is_string($peer) || ! in_array($peer, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        foreach (self::PROXY_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return true;
    }
}
