<?php

declare(strict_types=1);

namespace App\Support\Hmac;

/**
 * Firma de las peticiones entre el plugin y el hub, en las dos direcciones (docs/api-connector.md §2, S14).
 *
 * **v2** (plugin 0.1.4 en adelante):
 *
 *   X-IGR-Signature: t=<unix>,n=<nonce>,v2=hex(HMAC-SHA256(secreto, cadena))
 *   cadena = "IGR-HMAC-SHA256-V2" \n MÉTODO \n RUTA \n CONSULTA \n t \n n \n hex(SHA-256(cuerpo))
 *
 * - MÉTODO en mayúsculas; RUTA y CONSULTA en forma canónica (canonicalPath y canonicalQuery); el cuerpo son los bytes
 *   exactos que viajan (comprimidos si van con gzip; '' en un GET).
 * - n: de 16 a 64 caracteres [A-Za-z0-9_-], uno distinto por petición; quien recibe lo guarda mientras vale la
 *   ventana para rechazar una repetición.
 *
 * **v1** (plugins anteriores): t=<unix>,v1=hex(HMAC-SHA256(secreto, t + "." + cuerpo)), sin método, ruta ni nonce
 * (Signature). Si la cabecera trae v2 se verifica solo v2, aunque también traiga v1.
 *
 * El plugin tiene la misma implementación (connector/src/Support/Hmac.php); los vectores compartidos de
 * tests/fixtures/signature/vectors.json mantienen iguales los dos lados.
 */
final class RequestSignature
{
    public const LABEL = 'IGR-HMAC-SHA256-V2';

    /** Primera versión del plugin que firma y verifica la v2. */
    public const V2_MIN_PLUGIN_VERSION = '0.1.4';

    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    private const MAC_PATTERN = '/^[0-9a-f]{64}$/';

    public static function newNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Ruta y consulta canónicas de una URL absoluta («https://tienda.co/wp-json/…?a=1») o de un destino de petición
     * («/v1/s/config?a=1», como llega en REQUEST_URI). El esquema, el host y el fragmento no cuentan.
     *
     * @return array{0: string, 1: string}
     */
    public static function target(string $urlOrTarget): array
    {
        $hash = strpos($urlOrTarget, '#');
        $target = $hash === false ? $urlOrTarget : substr($urlOrTarget, 0, $hash);
        if (str_starts_with($target, '/')) {
            $q = strpos($target, '?');
            $path = $q === false ? $target : substr($target, 0, $q);
            $query = $q === false ? '' : substr($target, $q + 1);
        } else {
            $parts = parse_url($target);
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
        }

        return [self::canonicalPath($path), self::canonicalQuery($query)];
    }

    /**
     * Cada segmento se decodifica y se vuelve a codificar con RFC 3986 (rawurlencode), sin tocar las barras: «%7e» y
     * «~» dan lo mismo, y «%2F» sigue siendo distinto de «/». Una ruta vacía es «/».
     */
    public static function canonicalPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path)));
    }

    /**
     * Pares nombre=valor separados por «&»: «+» es un espacio, cada parte se decodifica y se vuelve a codificar con
     * RFC 3986, los pares sin «=» quedan con el valor vacío, los vacíos se descartan y se ordenan por nombre y luego
     * por valor (orden de bytes).
     */
    public static function canonicalQuery(string $query): string
    {
        $pairs = [];
        foreach (explode('&', $query) as $piece) {
            if ($piece === '') {
                continue;
            }
            $eq = strpos($piece, '=');
            $pairs[] = [
                self::encodeQueryPart($eq === false ? $piece : substr($piece, 0, $eq)),
                self::encodeQueryPart($eq === false ? '' : substr($piece, $eq + 1)),
            ];
        }
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return implode('&', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs));
    }

    public static function stringToSign(string $method, string $urlOrTarget, int $timestamp, string $nonce, string $body): string
    {
        [$path, $query] = self::target($urlOrTarget);

        return implode("\n", [self::LABEL, strtoupper($method), $path, $query, (string) $timestamp, $nonce, hash('sha256', $body)]);
    }

    /** Valor de X-IGR-Signature en v2. */
    public static function signV2(string $secret, string $method, string $urlOrTarget, string $body, ?int $timestamp = null, ?string $nonce = null): string
    {
        $t = $timestamp ?? time();
        $n = $nonce ?? self::newNonce();

        return 't='.$t.',n='.$n.',v2='.hash_hmac('sha256', self::stringToSign($method, $urlOrTarget, $t, $n, $body), $secret);
    }

    /** Valor de X-IGR-Signature en la versión pedida (1 o 2). */
    public static function sign(int $version, string $secret, string $method, string $urlOrTarget, string $body, ?int $timestamp = null, ?string $nonce = null): string
    {
        return $version >= 2
            ? self::signV2($secret, $method, $urlOrTarget, $body, $timestamp, $nonce)
            : Signature::sign($secret, $body, $timestamp);
    }

    /**
     * Lee la cabecera. Con `v2` exige un `n` válido; si no hay `v2`, se toma `v1`. Las claves desconocidas se ignoran.
     *
     * @return array{version: int, t: int, n: string|null, mac: string}|null
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null || $header === '' || strlen($header) > 1024) {
            return null;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = $parts['t'] ?? '';
        if ($t === '' || ! ctype_digit($t) || strlen($t) > 12) {
            return null;
        }
        if (array_key_exists('v2', $parts)) {
            $n = $parts['n'] ?? '';
            if (preg_match(self::NONCE_PATTERN, $n) !== 1 || preg_match(self::MAC_PATTERN, $parts['v2']) !== 1) {
                return null;
            }

            return ['version' => 2, 't' => (int) $t, 'n' => $n, 'mac' => $parts['v2']];
        }
        if (isset($parts['v1']) && preg_match(self::MAC_PATTERN, $parts['v1']) === 1) {
            return ['version' => 1, 't' => (int) $t, 'n' => null, 'mac' => $parts['v1']];
        }

        return null;
    }

    /**
     * Verifica una petición recibida. No guarda el nonce: eso lo hace quien recibe (SiteSignatures en el hub).
     *
     * @param  list<string>  $secrets  el secreto actual y, durante la rotación, el anterior
     */
    public static function verify(
        ?string $header,
        string $method,
        string $urlOrTarget,
        string $body,
        array $secrets,
        bool $acceptV1 = true,
        int $toleranceSeconds = 300,
        ?int $now = null,
    ): ?VerifiedSignature {
        $parsed = self::parse($header);
        if ($parsed === null || abs(($now ?? time()) - $parsed['t']) > $toleranceSeconds) {
            return null;
        }
        if ($parsed['version'] === 1 && ! $acceptV1) {
            return null;
        }
        $message = $parsed['version'] === 2
            ? self::stringToSign($method, $urlOrTarget, $parsed['t'], (string) $parsed['n'], $body)
            : null;
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $expected = $message !== null ? hash_hmac('sha256', $message, $secret) : Signature::digest($secret, $parsed['t'], $body);
            if (hash_equals($expected, $parsed['mac'])) {
                return new VerifiedSignature($parsed['version'], $parsed['t'], $parsed['n']);
            }
        }

        return null;
    }

    private static function encodeQueryPart(string $part): string
    {
        return rawurlencode(rawurldecode(str_replace('+', ' ', $part)));
    }
}
