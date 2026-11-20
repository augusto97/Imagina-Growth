<?php
/**
 * Firma HMAC de las peticiones (docs/api-connector.md §2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Firma y verifica `X-IGR-Signature` en las dos direcciones (plugin → hub y comandos del hub).
 *
 * - **v2** (desde la 0.1.4): `t=<unix>,n=<nonce>,v2=<hex>` con
 *   `v2 = hex( HMAC-SHA256( secreto, "IGR-HMAC-SHA256-V2" \n MÉTODO \n RUTA \n CONSULTA \n t \n n \n hex(SHA-256(cuerpo)) ) )`.
 *   La ruta y la consulta van en forma canónica (`canonical_path()` y `canonical_query()`), así una firma de una ruta
 *   no sirve para otra; `n` es único por petición y quien recibe lo guarda mientras vale la ventana.
 * - **v1** (compatibilidad): `t=<unix>,v1=<hex>` con `v1 = hex( HMAC-SHA256( secreto, t + "." + cuerpo ) )`.
 *
 * El cuerpo son los bytes exactos que viajan (comprimidos si van con gzip). El hub tiene la misma implementación
 * (`hub/app/Support/Hmac/RequestSignature.php`); los vectores de `hub/tests/fixtures/signature/vectors.json`
 * mantienen iguales los dos lados.
 */
final class Hmac {

	public const WINDOW_SECONDS = 300;

	public const LABEL = 'IGR-HMAC-SHA256-V2';

	public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

	private const MAC_PATTERN = '/^[0-9a-f]{64}$/';

	/**
	 * Arma el valor de la cabecera de firma v1.
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
	 * Calcula el HMAC v1 en hexadecimal.
	 *
	 * @param string $secret Secreto.
	 * @param int    $t      Hora Unix.
	 * @param string $body   Cuerpo.
	 */
	public static function digest( string $secret, int $t, string $body ): string {
		return hash_hmac( 'sha256', $t . '.' . $body, $secret );
	}

	/**
	 * Arma el valor de la cabecera de firma v2.
	 *
	 * @param string      $secret Secreto del sitio.
	 * @param string      $method Método HTTP.
	 * @param string      $url    URL completa o destino (`/ruta?consulta`) de la petición.
	 * @param string      $body   Bytes exactos del cuerpo ('' en un GET).
	 * @param int|null    $time   Hora Unix; por defecto, ahora.
	 * @param string|null $nonce  Nonce; por defecto, uno nuevo.
	 */
	public static function header_v2( string $secret, string $method, string $url, string $body, ?int $time = null, ?string $nonce = null ): string {
		$t = $time ?? time();
		$n = $nonce ?? self::nonce();
		return 't=' . $t . ',n=' . $n . ',v2=' . hash_hmac( 'sha256', self::string_to_sign( $method, $url, $t, $n, $body ), $secret );
	}

	/**
	 * Cabecera en la versión pedida (1 o 2).
	 *
	 * @param int    $version Versión.
	 * @param string $secret  Secreto.
	 * @param string $method  Método HTTP.
	 * @param string $url     URL o destino.
	 * @param string $body    Cuerpo.
	 */
	public static function sign( int $version, string $secret, string $method, string $url, string $body ): string {
		return $version >= 2 ? self::header_v2( $secret, $method, $url, $body ) : self::header( $secret, $body );
	}

	/**
	 * Nonce nuevo (32 hexadecimales).
	 */
	public static function nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Cadena que firma la v2.
	 *
	 * @param string $method Método HTTP.
	 * @param string $url    URL o destino.
	 * @param int    $t      Hora Unix.
	 * @param string $nonce  Nonce.
	 * @param string $body   Cuerpo.
	 */
	public static function string_to_sign( string $method, string $url, int $t, string $nonce, string $body ): string {
		list( $path, $query ) = self::target( $url );
		return implode( "\n", array( self::LABEL, strtoupper( $method ), $path, $query, (string) $t, $nonce, hash( 'sha256', $body ) ) );
	}

	/**
	 * Ruta y consulta canónicas de una URL absoluta o de un destino (`/ruta?consulta`, como `REQUEST_URI`). El esquema,
	 * el host y el fragmento no cuentan.
	 *
	 * @param string $url URL o destino.
	 * @return array{0:string, 1:string}
	 */
	public static function target( string $url ): array {
		$hash   = strpos( $url, '#' );
		$target = false === $hash ? $url : substr( $url, 0, $hash );
		if ( str_starts_with( $target, '/' ) ) {
			$q     = strpos( $target, '?' );
			$path  = false === $q ? $target : substr( $target, 0, $q );
			$query = false === $q ? '' : substr( $target, $q + 1 );
		} else {
			// parse_url y no wp_parse_url: el resultado debe ser el mismo del hub y la clase también corre sin WordPress.
			$parts = parse_url( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$path  = is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
			$query = is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '';
		}
		return array( self::canonical_path( $path ), self::canonical_query( $query ) );
	}

	/**
	 * Cada segmento se decodifica y se vuelve a codificar con RFC 3986 (`rawurlencode`), sin tocar las barras. Una
	 * ruta vacía es «/».
	 *
	 * @param string $path Ruta.
	 */
	public static function canonical_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}
		return implode( '/', array_map( static fn( string $segment ): string => rawurlencode( rawurldecode( $segment ) ), explode( '/', $path ) ) );
	}

	/**
	 * Pares `nombre=valor` separados por «&»: «+» es un espacio, cada parte se decodifica y se vuelve a codificar con
	 * RFC 3986, los pares sin «=» quedan con el valor vacío, los vacíos se descartan y se ordenan por nombre y luego
	 * por valor (orden de bytes).
	 *
	 * @param string $query Consulta sin «?».
	 */
	public static function canonical_query( string $query ): string {
		$pairs = array();
		foreach ( explode( '&', $query ) as $piece ) {
			if ( '' === $piece ) {
				continue;
			}
			$eq      = strpos( $piece, '=' );
			$pairs[] = array(
				self::query_part( false === $eq ? $piece : substr( $piece, 0, $eq ) ),
				self::query_part( false === $eq ? '' : substr( $piece, $eq + 1 ) ),
			);
		}
		usort( $pairs, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) ?: strcmp( $a[1], $b[1] ) );
		return implode( '&', array_map( static fn( array $pair ): string => $pair[0] . '=' . $pair[1], $pairs ) );
	}

	/**
	 * Lee una cabecera. Con `v2` exige un `n` válido; sin `v2`, toma los `v1` (puede haber varios).
	 *
	 * @param string $header Valor de la cabecera.
	 * @return array{t:int, v1:list<string>, n?:string, v2?:string}|null
	 */
	public static function parse( string $header ): ?array {
		if ( strlen( $header ) > 1024 ) {
			return null;
		}
		$t     = null;
		$n     = null;
		$v2    = null;
		$has2  = false;
		$sig   = array();
		$parts = explode( ',', $header );
		foreach ( $parts as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] && ctype_digit( $kv[1] ) && strlen( $kv[1] ) <= 12 ) {
				$t = (int) $kv[1];
			} elseif ( 'n' === $kv[0] ) {
				$n = $kv[1];
			} elseif ( 'v2' === $kv[0] ) {
				$has2 = true;
				$v2   = $kv[1];
			} elseif ( 'v1' === $kv[0] && 1 === preg_match( self::MAC_PATTERN, $kv[1] ) ) {
				$sig[] = $kv[1];
			}
		}
		if ( null === $t ) {
			return null;
		}
		if ( $has2 ) {
			if ( null === $n || 1 !== preg_match( self::NONCE_PATTERN, $n ) || null === $v2 || 1 !== preg_match( self::MAC_PATTERN, $v2 ) ) {
				return null;
			}
			return array(
				't'  => $t,
				'v1' => array(),
				'n'  => $n,
				'v2' => $v2,
			);
		}
		if ( array() === $sig ) {
			return null;
		}
		return array(
			't'  => $t,
			'v1' => $sig,
		);
	}

	/**
	 * Verifica una firma v1 contra uno o más secretos válidos (rotación: actual y anterior).
	 *
	 * @param string       $header  Valor de `X-IGR-Signature`.
	 * @param string       $body    Bytes exactos del cuerpo recibido.
	 * @param list<string> $secrets Secretos aceptados.
	 * @param int|null     $now     Hora Unix de referencia.
	 * @param int          $window  Ventana en segundos.
	 */
	public static function verify( string $header, string $body, array $secrets, ?int $now = null, int $window = self::WINDOW_SECONDS ): bool {
		$parsed = self::parse( $header );
		if ( null === $parsed || isset( $parsed['v2'] ) ) {
			return false;
		}
		return null !== self::check( $parsed, 'POST', array( '/' ), $body, $secrets, true, $now, $window );
	}

	/**
	 * Verifica una petición firmada en v1 o v2. Con v2 en la cabecera se verifica solo v2 (nunca se cae a v1).
	 *
	 * @param string       $header    Valor de `X-IGR-Signature`.
	 * @param string       $method    Método HTTP de la petición recibida.
	 * @param list<string> $targets   URL o destinos con los que pudo llegar (vale si coincide con alguno).
	 * @param string       $body      Bytes exactos del cuerpo recibido.
	 * @param list<string> $secrets   Secretos aceptados.
	 * @param bool         $accept_v1 ¿Se acepta todavía la v1?
	 * @param int|null     $now       Hora Unix de referencia.
	 * @param int          $window    Ventana en segundos.
	 * @return array{version:int, nonce:string|null}|null
	 */
	public static function verify_request( string $header, string $method, array $targets, string $body, array $secrets, bool $accept_v1 = true, ?int $now = null, int $window = self::WINDOW_SECONDS ): ?array {
		$parsed = self::parse( $header );
		return null === $parsed ? null : self::check( $parsed, $method, $targets, $body, $secrets, $accept_v1, $now, $window );
	}

	/**
	 * Compara una cabecera ya leída.
	 *
	 * @param array{t:int, v1:list<string>, n?:string, v2?:string} $parsed    Cabecera.
	 * @param string                                               $method    Método.
	 * @param list<string>                                         $targets   Destinos posibles.
	 * @param string                                               $body      Cuerpo.
	 * @param list<string>                                         $secrets   Secretos.
	 * @param bool                                                 $accept_v1 ¿Vale v1?.
	 * @param int|null                                             $now       Hora de referencia.
	 * @param int                                                  $window    Ventana.
	 * @return array{version:int, nonce:string|null}|null
	 */
	private static function check( array $parsed, string $method, array $targets, string $body, array $secrets, bool $accept_v1, ?int $now, int $window ): ?array {
		$now = $now ?? time();
		if ( abs( $now - $parsed['t'] ) > $window ) {
			return null;
		}
		$is_v2 = isset( $parsed['v2'], $parsed['n'] );
		if ( ! $is_v2 && ! $accept_v1 ) {
			return null;
		}
		$messages = array();
		if ( $is_v2 ) {
			foreach ( array_unique( $targets ) as $target ) {
				$messages[] = self::string_to_sign( $method, $target, $parsed['t'], $parsed['n'], $body );
			}
		}
		foreach ( $secrets as $secret ) {
			if ( '' === $secret ) {
				continue;
			}
			if ( $is_v2 ) {
				foreach ( $messages as $message ) {
					if ( hash_equals( hash_hmac( 'sha256', $message, $secret ), $parsed['v2'] ) ) {
						return array(
							'version' => 2,
							'nonce'   => $parsed['n'],
						);
					}
				}
				continue;
			}
			$expected = self::digest( $secret, $parsed['t'], $body );
			foreach ( $parsed['v1'] as $candidate ) {
				if ( hash_equals( $expected, $candidate ) ) {
					return array(
						'version' => 1,
						'nonce'   => null,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Una parte de la consulta en forma canónica.
	 *
	 * @param string $part Nombre o valor.
	 */
	private static function query_part( string $part ): string {
		return rawurlencode( rawurldecode( str_replace( '+', ' ', $part ) ) );
	}
}
