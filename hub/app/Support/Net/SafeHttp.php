<?php

declare(strict_types=1);

namespace App\Support\Net;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para URL que eligen los clientes (S5): revisa la URL, resuelve el DNS en ese momento, conecta a la IP
 * revisada (CURLOPT_RESOLVE, sin volver a resolver), no sigue redirecciones y solo habla HTTP o HTTPS.
 *
 * Uso: `SafeHttp::for($url)->timeout(5)->post($url, …)`. Lanza UnsafeUrl antes de conectar.
 */
final class SafeHttp
{
    public static function for(string $url, bool $httpsOnly = true): PendingRequest
    {
        return Http::withOptions(self::options($url, $httpsOnly))->withoutRedirecting();
    }

    /**
     * Opciones de Guzzle para conectar sin riesgo a la URL (también las usan las pruebas).
     *
     * @return array<string, mixed>
     */
    public static function options(string $url, bool $httpsOnly = true): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new UnsafeUrl('invalid_url');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('invalid_url');
        }
        if ($httpsOnly && $scheme !== 'https' && ! SafeUrl::allowsPrivate()) {
            throw new UnsafeUrl('https_required');
        }
        $pinned = SafeUrl::pin((string) $parts['host'], SafeUrl::port($parts));
        $curl = [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
        if (($resolve = $pinned->curlResolve()) !== null) {
            $curl[CURLOPT_RESOLVE] = [$resolve];
        }

        return ['allow_redirects' => false, 'curl' => $curl];
    }
}
