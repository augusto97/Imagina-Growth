<?php
/**
 * Firma HMAC de las peticiones (docs/api-connector.md §2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Firma y verifica `X-IGR-Signature: t=<unix>,v1=<hex>`.
 *
 * `v1 = hex( HMAC-SHA256( secreto, t + "." + cuerpo ) )`, donde el cuerpo son
 * los bytes exactos que viajan (comprimidos si van con gzip).
 */
final class Hmac {

	public const WINDOW_SECONDS = 300;

	/**
	 * Arma el valor de la cabecera de firma.
	 *
	 * @param string   $secret Secreto del sitio (`sk_…`), tal cual.
	 * @param string   $body   Bytes exactos del cuerpo ('' en un GET).
	 * @param int|null $time   Hora Unix; por defecto, ahora.
	 */
	public static function header( string $secret, string $body, ?int $time = null ): string {
		$t = $time ?? time();
		return 't=' . $t . ',v1=' . self::digest( $secret, $t, $body );
	}

	/**
	 * Calcula el HMAC en hexadecimal.
	 *
	 * @param string $secret Secreto.
	 * @param int    $t      Hora Unix.
	 * @param string $body   Cuerpo.
	 */
	public static function digest( string $secret, int $t, string $body ): string {
		return hash_hmac( 'sha256', $t . '.' . $body, $secret );
	}

	/**
	 * Lee una cabecera `t=…,v1=…`.
	 *
	 * @param string $header Valor de la cabecera.
	 * @return array{t:int, v1:list<string>}|null
	 */
	public static function parse( string $header ): ?array {
		$t   = null;
		$sig = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] && ctype_digit( $kv[1] ) ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] && 1 === preg_match( '/^[0-9a-f]{64}$/', $kv[1] ) ) {
				$sig[] = $kv[1];
			}
		}
		if ( null === $t || array() === $sig ) {
			return null;
		}
		return array(
			't'  => $t,
			'v1' => $sig,
		);
	}

	/**
	 * Verifica una firma contra uno o más secretos válidos (rotación: actual y anterior).
	 *
	 * @param string       $header  Valor de `X-IGR-Signature`.
	 * @param string       $body    Bytes exactos del cuerpo recibido.
	 * @param list<string> $secrets Secretos aceptados.
	 * @param int|null     $now     Hora Unix de referencia.
	 * @param int          $window  Ventana en segundos.
	 */
	public static function verify( string $header, string $body, array $secrets, ?int $now = null, int $window = self::WINDOW_SECONDS ): bool {
		$parsed = self::parse( $header );
		if ( null === $parsed ) {
			return false;
		}
		$now = $now ?? time();
		if ( abs( $now - $parsed['t'] ) > $window ) {
			return false;
		}
		foreach ( $secrets as $secret ) {
			if ( '' === $secret ) {
				continue;
			}
			$expected = self::digest( $secret, $parsed['t'], $body );
			foreach ( $parsed['v1'] as $candidate ) {
				if ( hash_equals( $expected, $candidate ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
