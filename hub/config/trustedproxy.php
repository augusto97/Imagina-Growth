<?php

/*
 * Proxies de confianza (S8). Laravel (Illuminate\Http\Middleware\TrustProxies) lee `trustedproxy.proxies` en cada
 * petición: solo de esas direcciones se cree X-Forwarded-For, X-Forwarded-Host y X-Forwarded-Proto, así que
 * $request->ip() es la IP real del visitante solo si la petición pasó por un proxy de la lista; si no, es la IP de
 * la conexión y un X-Forwarded-For escrito por el cliente no cuenta.
 *
 * En producción el único proxy es el Caddy de la propia máquina (127.0.0.1 o ::1): él resuelve la IP real (detrás de
 * Cloudflare, de CF-Connecting-IP) y la manda en X-Forwarded-For. IGR_TRUSTED_PROXIES acepta una lista separada por
 * comas de IP o rangos CIDR («10.0.0.0/8»). «*» confía en quien se conecte y solo se usa si se pide explícitamente
 * (por ejemplo, un balanceador sin IP fija en una red privada). Vacío: no confía en ningún proxy.
 */

$proxies = trim((string) env('IGR_TRUSTED_PROXIES', '127.0.0.1,::1'));

return [
    'proxies' => $proxies === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', $proxies)), static fn (string $proxy): bool => $proxy !== '')),
];
